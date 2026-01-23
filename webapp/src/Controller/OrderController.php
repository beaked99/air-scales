<?php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Stripe\Stripe;
use Stripe\Checkout\Session as StripeSession;

final class OrderController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em
    ) {}

    #[Route('/order', name: 'order_create')]
    public function create(Request $request): Response
    {
        // Get device type from query param (single or dual)
        $deviceType = $request->query->get('type', 'single');

        return $this->render('order/create.html.twig', [
            'device_type' => $deviceType,
        ]);
    }

    #[Route('/order/checkout', name: 'order_checkout', methods: ['POST'])]
    public function checkout(Request $request): Response
    {
        $user = $this->getUser();

        // Get form data
        $quantitySingle = (int) $request->request->get('quantity_single', 0);
        $quantityDual = (int) $request->request->get('quantity_dual', 0);
        $guestEmail = $request->request->get('email');
        $guestName = $request->request->get('name');
        $shippingAddress = $request->request->get('shipping_address');
        $shippingCity = $request->request->get('shipping_city');
        $shippingState = $request->request->get('shipping_state');
        $shippingZip = $request->request->get('shipping_zip');
        $shippingCountry = $request->request->get('shipping_country', 'US');

        // Validate at least one device is selected
        $totalDevices = $quantitySingle + $quantityDual;
        if ($totalDevices === 0) {
            $this->addFlash('error', 'Please select at least one device.');
            return $this->redirectToRoute('order_create');
        }

        // If not logged in, require guest email and name
        if (!$user && (empty($guestEmail) || empty($guestName))) {
            $this->addFlash('error', 'Please provide your email and name.');
            return $this->redirectToRoute('order_create');
        }

        // Combine shipping address
        $fullShippingAddress = sprintf(
            "%s\n%s, %s %s\n%s",
            $shippingAddress,
            $shippingCity,
            $shippingState,
            $shippingZip,
            $shippingCountry
        );

        // Fetch products
        $productSingle = $this->em->getRepository(Product::class)->findOneBy(['slug' => 'device-single-sensor', 'isActive' => true]);
        $productDual = $this->em->getRepository(Product::class)->findOneBy(['slug' => 'device-dual-sensor', 'isActive' => true]);

        if (($quantitySingle > 0 && !$productSingle) || ($quantityDual > 0 && !$productDual)) {
            $this->addFlash('error', 'One or more products not found. Please contact support at kevin@beaker.ca');
            return $this->redirectToRoute('order_create');
        }

        // Calculate pricing
        $priceSingle = $productSingle ? (float) $productSingle->getPriceUsd() : 150.00;
        $priceDual = $productDual ? (float) $productDual->getPriceUsd() : 225.00;

        $subtotal = ($quantitySingle * $priceSingle) + ($quantityDual * $priceDual);

        // Apply quantity discount
        $discountPercent = 0;
        if ($totalDevices >= 100) {
            $discountPercent = 25;
        } elseif ($totalDevices >= 50) {
            $discountPercent = 20;
        } elseif ($totalDevices >= 10) {
            $discountPercent = 15;
        } elseif ($totalDevices >= 2) {
            $discountPercent = 10;
        }

        $discountAmount = $subtotal * ($discountPercent / 100);
        $total = $subtotal - $discountAmount;

        // Build order items array
        $orderItems = [];
        if ($quantitySingle > 0) {
            $orderItems[] = [
                'product_id' => $productSingle->getId(),
                'product_name' => $productSingle->getName(),
                'slug' => 'device-single-sensor',
                'quantity' => $quantitySingle,
                'unit_price' => $priceSingle,
                'line_total' => $quantitySingle * $priceSingle,
            ];
        }
        if ($quantityDual > 0) {
            $orderItems[] = [
                'product_id' => $productDual->getId(),
                'product_name' => $productDual->getName(),
                'slug' => 'device-dual-sensor',
                'quantity' => $quantityDual,
                'unit_price' => $priceDual,
                'line_total' => $quantityDual * $priceDual,
            ];
        }

        // Create order in database
        $order = new Order();
        if ($user) {
            $order->setUser($user);
        } else {
            $order->setGuestEmail($guestEmail);
            $order->setGuestName($guestName);
        }
        $order->setOrderItems($orderItems);
        $order->setQuantity($totalDevices);
        $order->setSubtotal(number_format($subtotal, 2, '.', ''));
        $order->setDiscountAmount(number_format($discountAmount, 2, '.', ''));
        $order->setTotalPaid(number_format($total, 2, '.', ''));
        $order->setStatus('pending');
        $order->setShippingAddress($fullShippingAddress);

        $this->em->persist($order);
        $this->em->flush();

        // Set Stripe API key
        $stripeSecretKey = $this->getParameter('stripe_secret_key');
        Stripe::setApiKey($stripeSecretKey);

        // Build Stripe line items - use the discounted total price directly
        $stripeLineItems = [];

        // Calculate per-item price after discount is applied proportionally
        foreach ($orderItems as $item) {
            $itemSubtotal = $item['line_total'];
            $itemDiscount = $discountAmount * ($itemSubtotal / $subtotal); // Proportional discount
            $itemTotal = $itemSubtotal - $itemDiscount;
            $pricePerUnit = $itemTotal / $item['quantity'];

            $stripeLineItems[] = [
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => [
                        'name' => $discountPercent > 0
                            ? "{$item['product_name']} ({$discountPercent}% volume discount applied)"
                            : $item['product_name'],
                    ],
                    'unit_amount' => (int) round($pricePerUnit * 100), // Stripe uses cents
                ],
                'quantity' => $item['quantity'],
            ];
        }

        // Create Stripe Checkout Session
        try {
            $checkoutSession = StripeSession::create([
                'payment_method_types' => ['card'],
                'line_items' => $stripeLineItems,
                'mode' => 'payment',
                'success_url' => $this->generateUrl('order_success', ['order_id' => $order->getId()], 0),
                'cancel_url' => $this->generateUrl('order_cancel', ['order_id' => $order->getId()], 0),
                'customer_email' => $user ? $user->getEmail() : $guestEmail,
                'metadata' => [
                    'order_id' => $order->getId(),
                    'is_guest' => $user ? 'false' : 'true',
                    'total_devices' => $totalDevices,
                    'discount_percent' => $discountPercent,
                    'subtotal' => number_format($subtotal, 2, '.', ''),
                    'discount_amount' => number_format($discountAmount, 2, '.', ''),
                ],
            ]);

            // Save Stripe session ID to order
            $order->setStripeCheckoutSessionId($checkoutSession->id);
            $this->em->flush();

            // Redirect to Stripe Checkout
            return $this->redirect($checkoutSession->url);

        } catch (\Exception $e) {
            $this->addFlash('error', 'Payment processing error: ' . $e->getMessage());
            return $this->redirectToRoute('order_create');
        }
    }

    #[Route('/order/success/{order_id}', name: 'order_success')]
    public function success(int $order_id): Response
    {
        $order = $this->em->getRepository(Order::class)->find($order_id);

        if (!$order) {
            throw $this->createNotFoundException('Order not found');
        }

        // Verify ownership (either logged in user owns it, or it's their guest order)
        $user = $this->getUser();
        if ($user && $order->getUser() !== $user) {
            throw $this->createNotFoundException('Order not found');
        }

        // Update order status to processing
        if ($order->getStatus() === 'pending') {
            $order->setStatus('processing');
            $this->em->flush();
        }

        return $this->render('order/success.html.twig', [
            'order' => $order,
        ]);
    }

    #[Route('/order/cancel/{order_id}', name: 'order_cancel')]
    public function cancel(int $order_id): Response
    {
        $order = $this->em->getRepository(Order::class)->find($order_id);

        if (!$order) {
            throw $this->createNotFoundException('Order not found');
        }

        // Verify ownership
        $user = $this->getUser();
        if ($user && $order->getUser() !== $user) {
            throw $this->createNotFoundException('Order not found');
        }

        // Mark order as canceled
        if ($order->getStatus() === 'pending') {
            $order->setStatus('canceled');
            $this->em->flush();
        }

        return $this->render('order/cancel.html.twig', [
            'order' => $order,
        ]);
    }
}
