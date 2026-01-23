<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Subscription;
use App\Entity\User;
use App\Entity\Order;
use App\Entity\Product;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class StripeWebhookController extends AbstractController
{
    private LoggerInterface $logger;
    private EntityManagerInterface $em;
    private MailerInterface $mailer;

    public function __construct(
        LoggerInterface $logger,
        EntityManagerInterface $em,
        MailerInterface $mailer
    ) {
        $this->logger = $logger;
        $this->em = $em;
        $this->mailer = $mailer;
    }

    #[Route('/webhook/stripe', name: 'stripe_webhook', methods: ['POST'])]
    public function handleWebhook(Request $request): Response
    {
        $payload = $request->getContent();
        $sigHeader = $request->headers->get('stripe-signature');
        $webhookSecret = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? '';

        $this->logger->info('Webhook request received', [
            'has_signature' => !empty($sigHeader),
            'has_secret' => !empty($webhookSecret),
            'payload_length' => strlen($payload)
        ]);

        if (empty($webhookSecret)) {
            $this->logger->error('Webhook secret not configured');
            return new Response('Webhook secret not configured', 500);
        }

        try {
            // Verify webhook signature
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (\UnexpectedValueException $e) {
            $this->logger->error('Invalid webhook payload: ' . $e->getMessage());
            return new Response('Invalid payload', 400);
        } catch (SignatureVerificationException $e) {
            $this->logger->error('Invalid webhook signature: ' . $e->getMessage(), [
                'secret_prefix' => substr($webhookSecret, 0, 10)
            ]);
            return new Response('Invalid signature', 400);
        }

        $this->logger->info('Stripe webhook received', [
            'type' => $event->type,
            'id' => $event->id
        ]);

        // Handle the event
        try {
            switch ($event->type) {
                case 'checkout.session.completed':
                    $this->handleCheckoutCompleted($event->data->object);
                    break;

                case 'customer.subscription.created':
                case 'customer.subscription.updated':
                    $this->handleSubscriptionUpdated($event->data->object);
                    break;

                case 'customer.subscription.deleted':
                    $this->handleSubscriptionDeleted($event->data->object);
                    break;

                case 'invoice.payment_succeeded':
                    $this->handlePaymentSucceeded($event->data->object);
                    break;

                case 'invoice.payment_failed':
                    $this->handlePaymentFailed($event->data->object);
                    break;

                default:
                    $this->logger->info('Unhandled webhook type: ' . $event->type);
            }
        } catch (\Exception $e) {
            $this->logger->error('Webhook processing error: ' . $e->getMessage(), [
                'event_type' => $event->type,
                'trace' => $e->getTraceAsString()
            ]);
            return new Response('Webhook processing error', 500);
        }

        return new Response('Webhook processed', 200);
    }

    private function handleCheckoutCompleted($session): void
    {
        $this->logger->info('Processing checkout.session.completed', [
            'session_id' => $session->id,
            'mode' => $session->mode
        ]);

        // Get user by Stripe customer ID
        $user = $this->em->getRepository(User::class)
            ->findOneBy(['stripe_customer_id' => $session->customer]);

        if (!$user) {
            $this->logger->error('User not found for customer: ' . $session->customer);
            return;
        }

        if ($session->mode === 'subscription') {
            // Handle subscription purchase
            $this->createOrUpdateSubscription($user, $session);
        } elseif ($session->mode === 'payment') {
            // Handle one-time payment (device purchase)
            $this->createDeviceOrder($user, $session);
        }
    }

    private function createOrUpdateSubscription(User $user, $session): void
    {
        $stripeSubscriptionId = $session->subscription;

        if (!$stripeSubscriptionId) {
            $this->logger->error('No subscription ID in checkout session');
            return;
        }

        try {
            // Fetch full subscription details from Stripe
            \Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);
            $stripeSubscription = \Stripe\Subscription::retrieve($stripeSubscriptionId);

            $this->logger->info('Stripe subscription retrieved', [
                'id' => $stripeSubscription->id,
                'current_period_start' => $stripeSubscription->current_period_start,
                'current_period_end' => $stripeSubscription->current_period_end,
                'status' => $stripeSubscription->status,
                'object' => $stripeSubscription->object,
                'toArray' => $stripeSubscription->toArray()
            ]);

            // Find or create subscription
            $subscription = $this->em->getRepository(Subscription::class)
                ->findOneBy(['stripe_subscription_id' => $stripeSubscriptionId]);

            if (!$subscription) {
                $subscription = new Subscription();
                $subscription->setUser($user);
                $subscription->setStripeSubscriptionId($stripeSubscriptionId);
            }

            $subscription->setStatus($stripeSubscription->status);

            // Convert Unix timestamps to DateTimeImmutable
            // Get timestamps from the first subscription item
            $subscriptionData = $stripeSubscription->toArray();

            if (!empty($subscriptionData['items']['data'][0]['current_period_start'])) {
                $periodStart = (new \DateTimeImmutable())->setTimestamp((int)$subscriptionData['items']['data'][0]['current_period_start']);
                $subscription->setCurrentPeriodStart($periodStart);
            }

            if (!empty($subscriptionData['items']['data'][0]['current_period_end'])) {
                $periodEnd = (new \DateTimeImmutable())->setTimestamp((int)$subscriptionData['items']['data'][0]['current_period_end']);
                $subscription->setCurrentPeriodEnd($periodEnd);
            }

            // Detect plan type from Stripe price ID (source of truth)
            if (!empty($subscriptionData['items']['data'][0]['price']['id'])) {
                $stripePriceId = $subscriptionData['items']['data'][0]['price']['id'];

                // Look up product by price ID to determine plan type
                $product = $this->em->getRepository(Product::class)
                    ->findOneBy(['stripePriceId' => $stripePriceId]);

                if ($product) {
                    // Set plan type based on product slug
                    if (str_contains($product->getSlug(), 'yearly')) {
                        $subscription->setPlanType('yearly');
                    } elseif (str_contains($product->getSlug(), 'monthly')) {
                        $subscription->setPlanType('monthly');
                    }

                    // Check if this fulfills a scheduled change
                    if ($subscription->getScheduledStripePriceId() === $stripePriceId) {
                        // Scheduled change has taken effect - clear scheduled fields
                        $subscription->setScheduledPlanType(null);
                        $subscription->setScheduledStripePriceId(null);
                        $subscription->setScheduledChangeEffectiveAt(null);

                        $this->logger->info('Scheduled plan change finalized', [
                            'subscription_id' => $subscription->getId(),
                            'new_plan_type' => $subscription->getPlanType()
                        ]);
                    }
                }
            }

            $this->em->persist($subscription);
            $this->em->flush();

            $this->logger->info('Subscription created/updated', [
                'user_id' => $user->getId(),
                'subscription_id' => $subscription->getId(),
                'stripe_subscription_id' => $stripeSubscriptionId,
                'status' => $subscription->getStatus()
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to create/update subscription', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function createDeviceOrder(User $user, $session): void
    {
        // Retrieve full session with line items expanded
        \Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);
        $fullSession = \Stripe\Checkout\Session::retrieve([
            'id' => $session->id,
            'expand' => ['line_items']
        ]);

        // Extract product info from line items
        $lineItems = $fullSession->line_items->data ?? [];

        if (empty($lineItems)) {
            $this->logger->warning('No line items in checkout session');
            return;
        }

        foreach ($lineItems as $item) {
            $stripePriceId = $item->price->id;

            // Find product by Stripe price ID
            $product = $this->em->getRepository(Product::class)
                ->findOneBy(['stripePriceId' => $stripePriceId]);

            if (!$product) {
                $this->logger->warning('Product not found for price ID: ' . $stripePriceId);
                continue;
            }

            // Create order
            $order = new Order();
            $order->setUser($user);
            $order->setProduct($product);
            $order->setQuantity($item->quantity);
            $order->setTotalPaid($session->amount_total / 100); // Convert cents to dollars
            $order->setStatus('pending');
            $order->setStripeCheckoutSessionId($session->id);
            $order->setStripePaymentIntentId($session->payment_intent);

            // Extract shipping address if available
            if ($session->shipping_details) {
                $address = $session->shipping_details->address;
                $shippingAddress = sprintf(
                    "%s\n%s\n%s, %s %s\n%s",
                    $session->shipping_details->name,
                    $address->line1 . ($address->line2 ? "\n" . $address->line2 : ''),
                    $address->city,
                    $address->state,
                    $address->postal_code,
                    $address->country
                );
                $order->setShippingAddress($shippingAddress);
            }

            $this->em->persist($order);

            $this->logger->info('Device order created', [
                'user_id' => $user->getId(),
                'product_id' => $product->getId(),
                'order_id' => $order->getId()
            ]);

            // Send email notification to admin
            $this->sendOrderNotificationEmail($order);
        }

        $this->em->flush();
    }

    private function handleSubscriptionUpdated($subscription): void
    {
        $this->logger->info('Processing subscription updated', [
            'subscription_id' => $subscription->id
        ]);

        // Retrieve full subscription from Stripe to ensure we have all fields
        \Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);
        $stripeSubscription = \Stripe\Subscription::retrieve($subscription->id);

        $sub = $this->em->getRepository(Subscription::class)
            ->findOneBy(['stripe_subscription_id' => $stripeSubscription->id]);

        if (!$sub) {
            $this->logger->warning('Subscription not found: ' . $stripeSubscription->id);
            return;
        }

        // Update subscription details
        $sub->setStatus($stripeSubscription->status);

        // Get timestamps from the first subscription item
        $subscriptionData = $stripeSubscription->toArray();

        if (!empty($subscriptionData['items']['data'][0]['current_period_start'])) {
            $periodStart = (new \DateTimeImmutable())->setTimestamp((int)$subscriptionData['items']['data'][0]['current_period_start']);
            $sub->setCurrentPeriodStart($periodStart);
        }

        if (!empty($subscriptionData['items']['data'][0]['current_period_end'])) {
            $periodEnd = (new \DateTimeImmutable())->setTimestamp((int)$subscriptionData['items']['data'][0]['current_period_end']);
            $sub->setCurrentPeriodEnd($periodEnd);
        }

        // Detect plan type from Stripe price ID (source of truth)
        if (!empty($subscriptionData['items']['data'][0]['price']['id'])) {
            $stripePriceId = $subscriptionData['items']['data'][0]['price']['id'];

            // Look up product by price ID to determine plan type
            $product = $this->em->getRepository(Product::class)
                ->findOneBy(['stripePriceId' => $stripePriceId]);

            if ($product) {
                // Set plan type based on product slug
                if (str_contains($product->getSlug(), 'yearly')) {
                    $sub->setPlanType('yearly');
                } elseif (str_contains($product->getSlug(), 'monthly')) {
                    $sub->setPlanType('monthly');
                }

                // Check if this fulfills a scheduled change
                if ($sub->getScheduledStripePriceId() === $stripePriceId) {
                    // Scheduled change has taken effect - clear scheduled fields
                    $sub->setScheduledPlanType(null);
                    $sub->setScheduledStripePriceId(null);
                    $sub->setScheduledChangeEffectiveAt(null);

                    $this->logger->info('Scheduled plan change finalized', [
                        'subscription_id' => $sub->getId(),
                        'new_plan_type' => $sub->getPlanType()
                    ]);
                }
            }
        }

        if ($stripeSubscription->cancel_at_period_end) {
            $sub->setStatus('canceled');
            $sub->setCanceledAt(new \DateTimeImmutable());
        }

        $this->em->flush();

        $this->logger->info('Subscription updated', [
            'subscription_id' => $sub->getId(),
            'status' => $sub->getStatus()
        ]);
    }

    private function handleSubscriptionDeleted($subscription): void
    {
        $this->logger->info('Processing subscription deleted', [
            'subscription_id' => $subscription->id
        ]);

        $sub = $this->em->getRepository(Subscription::class)
            ->findOneBy(['stripe_subscription_id' => $subscription->id]);

        if (!$sub) {
            $this->logger->warning('Subscription not found: ' . $subscription->id);
            return;
        }

        $sub->setStatus('expired');
        $this->em->flush();

        $this->logger->info('Subscription marked as expired', [
            'subscription_id' => $sub->getId()
        ]);
    }

    private function handlePaymentSucceeded($invoice): void
    {
        $this->logger->info('Processing payment succeeded', [
            'invoice_id' => $invoice->id,
            'subscription_id' => $invoice->subscription
        ]);

        if ($invoice->subscription) {
            // Renewal payment - update subscription
            $sub = $this->em->getRepository(Subscription::class)
                ->findOneBy(['stripe_subscription_id' => $invoice->subscription]);

            if ($sub) {
                $sub->setStatus('active');

                if (!empty($invoice->period_end)) {
                    $periodEnd = (new \DateTimeImmutable())->setTimestamp((int)$invoice->period_end);
                    $sub->setCurrentPeriodEnd($periodEnd);
                }

                $this->em->flush();

                $this->logger->info('Subscription renewed', [
                    'subscription_id' => $sub->getId()
                ]);
            }
        }
    }

    private function handlePaymentFailed($invoice): void
    {
        $this->logger->info('Processing payment failed', [
            'invoice_id' => $invoice->id,
            'subscription_id' => $invoice->subscription
        ]);

        if ($invoice->subscription) {
            $sub = $this->em->getRepository(Subscription::class)
                ->findOneBy(['stripe_subscription_id' => $invoice->subscription]);

            if ($sub) {
                $sub->setStatus('past_due');
                $this->em->flush();

                $this->logger->info('Subscription marked as past_due', [
                    'subscription_id' => $sub->getId()
                ]);

                // TODO: Send email to user about failed payment
            }
        }
    }

    private function sendOrderNotificationEmail(Order $order): void
    {
        try {
            $email = (new Email())
                ->from($_ENV['MAILER_FROM'] ?? 'noreply@airscales.com')
                ->to($_ENV['ADMIN_EMAIL'] ?? 'admin@airscales.com')
                ->subject('New Device Order #' . $order->getId())
                ->html(sprintf(
                    '<h2>New Device Order</h2>
                    <p><strong>Order #:</strong> %d</p>
                    <p><strong>Customer:</strong> %s (%s)</p>
                    <p><strong>Product:</strong> %s</p>
                    <p><strong>Quantity:</strong> %d</p>
                    <p><strong>Total Paid:</strong> $%s</p>
                    <p><strong>Shipping Address:</strong><br>%s</p>
                    <p><a href="https://beaker.ca/admin?crudAction=detail&crudControllerFqcn=App%%5CController%%5CAdmin%%5COrderCrudController&entityId=%d">View Order in Admin</a></p>',
                    $order->getId(),
                    $order->getUser()->getFullName(),
                    $order->getUser()->getEmail(),
                    $order->getProduct()->getName(),
                    $order->getQuantity(),
                    $order->getTotalPaid(),
                    nl2br($order->getShippingAddress() ?? 'N/A'),
                    $order->getId()
                ));

            $this->mailer->send($email);

            $this->logger->info('Order notification email sent', [
                'order_id' => $order->getId()
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send order notification email: ' . $e->getMessage());
        }
    }
}
