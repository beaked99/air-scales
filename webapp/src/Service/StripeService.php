<?php

namespace App\Service;

use Stripe\Stripe;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\BillingPortal\Session as BillingPortalSession;
use Stripe\Customer;
use Stripe\Subscription;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;

class StripeService
{
    private string $secretKey;
    private string $publishableKey;
    private EntityManagerInterface $em;

    public function __construct(
        ParameterBagInterface $params,
        EntityManagerInterface $em
    ) {
        $this->secretKey = $_ENV['STRIPE_SECRET_KEY'] ?? '';
        $this->publishableKey = $_ENV['STRIPE_PUBLISHABLE_KEY'] ?? '';
        $this->em = $em;

        Stripe::setApiKey($this->secretKey);
    }

    /**
     * Get or create Stripe customer for user
     */
    public function getOrCreateCustomer(User $user): Customer
    {
        // If user already has a Stripe customer ID, retrieve it
        if ($user->getStripeCustomerId()) {
            try {
                return Customer::retrieve($user->getStripeCustomerId());
            } catch (ApiErrorException $e) {
                // Customer not found, create new one
            }
        }

        // Create new Stripe customer
        $customer = Customer::create([
            'email' => $user->getEmail(),
            'name' => $user->getFullName(),
            'metadata' => [
                'user_id' => $user->getId()
            ]
        ]);

        // Save customer ID to user
        $user->setStripeCustomerId($customer->id);
        $this->em->flush();

        return $customer;
    }

    /**
     * Create Stripe Checkout Session for subscription
     */
    public function createSubscriptionCheckout(
        User $user,
        string $priceId,
        string $successUrl,
        string $cancelUrl
    ): CheckoutSession {
        $customer = $this->getOrCreateCustomer($user);

        return CheckoutSession::create([
            'customer' => $customer->id,
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price' => $priceId,
                'quantity' => 1,
            ]],
            'mode' => 'subscription',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'user_id' => $user->getId()
            ]
        ]);
    }

    /**
     * Create Stripe Checkout Session for one-time device purchase
     */
    public function createDeviceCheckout(
        User $user,
        string $priceId,
        int $quantity,
        string $successUrl,
        string $cancelUrl
    ): CheckoutSession {
        $customer = $this->getOrCreateCustomer($user);

        return CheckoutSession::create([
            'customer' => $customer->id,
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price' => $priceId,
                'quantity' => $quantity,
            ]],
            'mode' => 'payment',
            'shipping_address_collection' => [
                'allowed_countries' => ['US', 'CA'], // Adjust based on where you ship
            ],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'user_id' => $user->getId(),
                'device_quantity' => $quantity
            ]
        ]);
    }

    /**
     * Create Stripe Customer Portal session
     */
    public function createPortalSession(User $user, string $returnUrl): BillingPortalSession
    {
        $customer = $this->getOrCreateCustomer($user);

        return BillingPortalSession::create([
            'customer' => $customer->id,
            'return_url' => $returnUrl,
        ]);
    }

    /**
     * Cancel subscription at period end
     */
    public function cancelSubscriptionAtPeriodEnd(string $subscriptionId): Subscription
    {
        return Subscription::update($subscriptionId, [
            'cancel_at_period_end' => true
        ]);
    }

    /**
     * Reactivate a canceled subscription
     */
    public function reactivateSubscription(string $subscriptionId): Subscription
    {
        return Subscription::update($subscriptionId, [
            'cancel_at_period_end' => false
        ]);
    }

    /**
     * Update subscription to new price (upgrade/downgrade)
     */
    public function updateSubscriptionPrice(string $subscriptionId, string $newPriceId): Subscription
    {
        $subscription = Subscription::retrieve($subscriptionId);

        return Subscription::update($subscriptionId, [
            'items' => [[
                'id' => $subscription->items->data[0]->id,
                'price' => $newPriceId,
            ]],
            'proration_behavior' => 'always_invoice',
        ]);
    }

    /**
     * Schedule subscription price change at next renewal (no proration)
     * Uses Subscription Schedules for interval changes (monthly <-> yearly)
     */
    public function scheduleSubscriptionPriceChange(string $subscriptionId, string $newPriceId): void
    {
        $subscription = Subscription::retrieve($subscriptionId);

        // Check if subscription already has a schedule
        if ($subscription->schedule) {
            // Update existing schedule to add the new plan phase
            $scheduleId = is_string($subscription->schedule) ? $subscription->schedule : $subscription->schedule->id;
            $schedule = \Stripe\SubscriptionSchedule::retrieve($scheduleId);

            // Extract items from phase 1 properly
            $phase1Items = [];
            foreach ($schedule->phases[0]->items as $item) {
                $phase1Items[] = [
                    'price' => $item->price,
                    'quantity' => $item->quantity ?? 1,
                ];
            }

            // Update the schedule with new phases
            \Stripe\SubscriptionSchedule::update($scheduleId, [
                'phases' => [
                    [
                        // Phase 1: Current plan until period end (keep existing)
                        'items' => $phase1Items,
                        'start_date' => $schedule->phases[0]->start_date,
                        'end_date' => $schedule->phases[0]->end_date,
                    ],
                    [
                        // Phase 2: New plan - continues indefinitely until released
                        'items' => [[
                            'price' => $newPriceId,
                            'quantity' => 1,
                        ]],
                    ],
                ],
                'end_behavior' => 'release',
            ]);
        } else {
            // Create a new subscription schedule from the subscription
            \Stripe\SubscriptionSchedule::create([
                'from_subscription' => $subscriptionId,
            ]);

            // Retrieve the newly created schedule
            $subscription = Subscription::retrieve($subscriptionId);
            $scheduleId = is_string($subscription->schedule) ? $subscription->schedule : $subscription->schedule->id;
            $schedule = \Stripe\SubscriptionSchedule::retrieve($scheduleId);

            // Now update it to add the second phase
            \Stripe\SubscriptionSchedule::update($scheduleId, [
                'phases' => [
                    [
                        // Phase 1: Current plan until period end
                        'items' => [[
                            'price' => $subscription->items->data[0]->price->id,
                            'quantity' => 1,
                        ]],
                        'start_date' => $schedule->phases[0]->start_date,
                        'end_date' => $schedule->phases[0]->end_date,
                    ],
                    [
                        // Phase 2: New plan - continues indefinitely until released
                        'items' => [[
                            'price' => $newPriceId,
                            'quantity' => 1,
                        ]],
                    ],
                ],
                'end_behavior' => 'release',
            ]);
        }
    }

    /**
     * Get publishable key for frontend
     */
    public function getPublishableKey(): string
    {
        return $this->publishableKey;
    }
}
