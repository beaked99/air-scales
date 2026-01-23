<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\StripeService;

#[IsGranted('ROLE_USER')]
class UserController extends AbstractController
{
    #[Route('/profile', name: 'user_profile')]
    public function profile(): Response
    {
        $user = $this->getUser();

        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $subscription = $user->getSubscription();

        // Calculate subscription details
        $subscriptionStatus = null;
        $daysRemaining = null;
        $expiresAt = null;
        $isPromotional = false;
        $hasDeviceTrial = false;
        $deviceTrialEndsAt = null;

        if ($subscription) {
            $subscriptionStatus = $subscription->getStatus();
            $daysRemaining = $subscription->getDaysUntilExpiration();
            $isPromotional = $subscription->isPromotional();
            $hasDeviceTrial = $subscription->hasDeviceTrial();
            $deviceTrialEndsAt = $subscription->getDeviceTrialEndsAt();

            if ($subscription->getCurrentPeriodEnd()) {
                $expiresAt = $subscription->getCurrentPeriodEnd();
            } elseif ($deviceTrialEndsAt) {
                $expiresAt = $deviceTrialEndsAt;
            }
        }

        return $this->render('user/profile.html.twig', [
            'user' => $user,
            'subscription' => $subscription,
            'subscriptionStatus' => $subscriptionStatus,
            'daysRemaining' => $daysRemaining,
            'expiresAt' => $expiresAt,
            'isPromotional' => $isPromotional,
            'hasDeviceTrial' => $hasDeviceTrial,
            'deviceTrialEndsAt' => $deviceTrialEndsAt,
        ]);
    }

    #[Route('/api/profile/update', name: 'profile_update', methods: ['POST'])]
    public function updateProfile(
        Request $request,
        EntityManagerInterface $em
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse(['error' => 'Invalid JSON'], 400);
        }

        // Update basic profile fields
        if (isset($data['first_name'])) {
            $user->setFirstName($data['first_name']);
        }

        if (isset($data['last_name'])) {
            $user->setLastName($data['last_name']);
        }

        if (isset($data['email'])) {
            // Check if email is already taken by another user
            $existingUser = $em->getRepository(\App\Entity\User::class)->findOneBy(['email' => $data['email']]);
            if ($existingUser && $existingUser->getId() !== $user->getId()) {
                return new JsonResponse(['error' => 'Email already in use'], 400);
            }
            $user->setEmail($data['email']);
        }

        $user->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Profile updated successfully',
            'user' => [
                'first_name' => $user->getFirstName(),
                'last_name' => $user->getLastName(),
                'email' => $user->getEmail()
            ]
        ]);
    }

    #[Route('/api/profile/change-password', name: 'profile_change_password', methods: ['POST'])]
    public function changePassword(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['current_password']) || !isset($data['new_password'])) {
            return new JsonResponse(['error' => 'Current password and new password are required'], 400);
        }

        // Verify current password
        if (!$passwordHasher->isPasswordValid($user, $data['current_password'])) {
            return new JsonResponse(['error' => 'Current password is incorrect'], 400);
        }

        // Validate new password strength
        if (strlen($data['new_password']) < 8) {
            return new JsonResponse(['error' => 'New password must be at least 8 characters'], 400);
        }

        // Hash and set new password
        $hashedPassword = $passwordHasher->hashPassword($user, $data['new_password']);
        $user->setPassword($hashedPassword);
        $user->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Password changed successfully'
        ]);
    }

    #[Route('/api/subscription/cancel', name: 'subscription_cancel', methods: ['POST'])]
    public function cancelSubscription(
        EntityManagerInterface $em,
        StripeService $stripe
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        $subscription = $user->getSubscription();

        if (!$subscription) {
            return new JsonResponse(['error' => 'No subscription found'], 404);
        }

        try {
            // Cancel subscription in Stripe (at period end, not immediately)
            if ($subscription->getStripeSubscriptionId()) {
                $stripe->cancelSubscriptionAtPeriodEnd($subscription->getStripeSubscriptionId());
            }

            $subscription->setStatus('canceled');
            $subscription->setCanceledAt(new \DateTimeImmutable());
            $em->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Subscription will not renew. You retain access until ' .
                            ($subscription->getCurrentPeriodEnd() ? $subscription->getCurrentPeriodEnd()->format('M j, Y') : 'trial ends')
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Failed to cancel subscription: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/api/subscription/reactivate', name: 'subscription_reactivate', methods: ['POST'])]
    public function reactivateSubscription(
        EntityManagerInterface $em,
        StripeService $stripe
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        $subscription = $user->getSubscription();

        if (!$subscription) {
            return new JsonResponse(['error' => 'No subscription found'], 404);
        }

        if ($subscription->getStatus() !== 'canceled') {
            return new JsonResponse(['error' => 'Subscription is not canceled'], 400);
        }

        try {
            // Reactivate subscription in Stripe
            if ($subscription->getStripeSubscriptionId()) {
                $stripe->reactivateSubscription($subscription->getStripeSubscriptionId());
            }

            $subscription->setStatus('active');
            $subscription->setCanceledAt(null);
            $em->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Subscription reactivated successfully'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Failed to reactivate subscription: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/api/subscription/portal', name: 'subscription_portal', methods: ['GET'])]
    public function getStripePortalUrl(StripeService $stripe): JsonResponse
    {
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        try {
            // Generate Stripe Customer Portal URL
            $returnUrl = $this->generateUrl('user_profile', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);
            $session = $stripe->createPortalSession($user, $returnUrl);

            return new JsonResponse([
                'url' => $session->url
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Failed to create portal session: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/api/subscription/checkout/monthly', name: 'subscription_checkout_monthly', methods: ['POST'])]
    public function checkoutMonthly(
        EntityManagerInterface $em,
        StripeService $stripe
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        try {
            // Get monthly subscription product
            $product = $em->getRepository(\App\Entity\Product::class)
                ->findOneBy(['slug' => 'monthly-subscription', 'isActive' => true]);

            if (!$product || !$product->getStripePriceId()) {
                return new JsonResponse(['error' => 'Monthly subscription not configured'], 500);
            }

            $priceId = $product->getStripePriceId();

            // Generate success and cancel URLs
            $successUrl = $this->generateUrl('user_profile', ['success' => 'subscription'], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);
            $cancelUrl = $this->generateUrl('user_profile', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);

            // Create Stripe Checkout Session
            $session = $stripe->createSubscriptionCheckout($user, $priceId, $successUrl, $cancelUrl);

            return new JsonResponse([
                'url' => $session->url
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Failed to create checkout session: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/api/subscription/checkout/yearly', name: 'subscription_checkout_yearly', methods: ['POST'])]
    public function checkoutYearly(
        EntityManagerInterface $em,
        StripeService $stripe
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        try {
            // Get yearly subscription product
            $product = $em->getRepository(\App\Entity\Product::class)
                ->findOneBy(['slug' => 'yearly-subscription', 'isActive' => true]);

            if (!$product || !$product->getStripePriceId()) {
                return new JsonResponse(['error' => 'Yearly subscription not configured'], 500);
            }

            $priceId = $product->getStripePriceId();

            // Generate success and cancel URLs
            $successUrl = $this->generateUrl('user_profile', ['success' => 'subscription'], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);
            $cancelUrl = $this->generateUrl('user_profile', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);

            // Create Stripe Checkout Session
            $session = $stripe->createSubscriptionCheckout($user, $priceId, $successUrl, $cancelUrl);

            return new JsonResponse([
                'url' => $session->url
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Failed to create checkout session: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/api/subscription/upgrade-yearly', name: 'subscription_upgrade_yearly', methods: ['POST'])]
    public function upgradeToYearly(
        EntityManagerInterface $em,
        StripeService $stripe
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        $subscription = $user->getSubscription();

        if (!$subscription) {
            return new JsonResponse(['error' => 'No subscription found'], 404);
        }

        if (!$subscription->getStripeSubscriptionId()) {
            return new JsonResponse(['error' => 'No Stripe subscription ID'], 400);
        }

        // Check if user is on monthly plan
        if ($subscription->getPlanType() !== 'monthly') {
            return new JsonResponse(['error' => 'Can only upgrade from monthly plan'], 400);
        }

        // Check if they already have a scheduled change
        if ($subscription->getScheduledPlanType() !== null) {
            return new JsonResponse(['error' => 'You already have a scheduled plan change. Cancel it first.'], 400);
        }

        try {
            // Get yearly subscription product
            $yearlyProduct = $em->getRepository(\App\Entity\Product::class)
                ->findOneBy(['slug' => 'yearly-subscription', 'isActive' => true]);

            if (!$yearlyProduct || !$yearlyProduct->getStripePriceId()) {
                return new JsonResponse(['error' => 'Yearly subscription not configured'], 500);
            }

            // Schedule price change to yearly at next renewal (no proration)
            $stripe->scheduleSubscriptionPriceChange(
                $subscription->getStripeSubscriptionId(),
                $yearlyProduct->getStripePriceId()
            );

            // Store scheduled change in database (don't change current plan_type yet!)
            $subscription->setScheduledPlanType('yearly');
            $subscription->setScheduledStripePriceId($yearlyProduct->getStripePriceId());
            $subscription->setScheduledChangeEffectiveAt($subscription->getCurrentPeriodEnd());
            $em->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Your subscription will upgrade to yearly at the next renewal on ' .
                            ($subscription->getCurrentPeriodEnd() ? $subscription->getCurrentPeriodEnd()->format('M j, Y') : 'renewal date')
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Failed to schedule upgrade: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/api/subscription/downgrade-monthly', name: 'subscription_downgrade_monthly', methods: ['POST'])]
    public function downgradeToMonthly(
        EntityManagerInterface $em,
        StripeService $stripe
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        $subscription = $user->getSubscription();

        if (!$subscription) {
            return new JsonResponse(['error' => 'No subscription found'], 404);
        }

        if (!$subscription->getStripeSubscriptionId()) {
            return new JsonResponse(['error' => 'No Stripe subscription ID'], 400);
        }

        // Check if user is on yearly plan
        if ($subscription->getPlanType() !== 'yearly') {
            return new JsonResponse(['error' => 'Can only downgrade from yearly plan'], 400);
        }

        // Check if they already have a scheduled change
        if ($subscription->getScheduledPlanType() !== null) {
            return new JsonResponse(['error' => 'You already have a scheduled plan change. Cancel it first.'], 400);
        }

        try {
            // Get monthly subscription product
            $monthlyProduct = $em->getRepository(\App\Entity\Product::class)
                ->findOneBy(['slug' => 'monthly-subscription', 'isActive' => true]);

            if (!$monthlyProduct || !$monthlyProduct->getStripePriceId()) {
                return new JsonResponse(['error' => 'Monthly subscription not configured'], 500);
            }

            // Schedule price change to monthly at next renewal (no proration)
            $stripe->scheduleSubscriptionPriceChange(
                $subscription->getStripeSubscriptionId(),
                $monthlyProduct->getStripePriceId()
            );

            // Store scheduled change in database (don't change current plan_type yet!)
            $subscription->setScheduledPlanType('monthly');
            $subscription->setScheduledStripePriceId($monthlyProduct->getStripePriceId());
            $subscription->setScheduledChangeEffectiveAt($subscription->getCurrentPeriodEnd());
            $em->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Your subscription will downgrade to monthly at the next renewal on ' .
                            ($subscription->getCurrentPeriodEnd() ? $subscription->getCurrentPeriodEnd()->format('M j, Y') : 'renewal date')
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Failed to schedule downgrade: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/api/subscription/cancel-scheduled-change', name: 'subscription_cancel_scheduled_change', methods: ['POST'])]
    public function cancelScheduledChange(
        EntityManagerInterface $em,
        StripeService $stripe
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        $subscription = $user->getSubscription();

        if (!$subscription) {
            return new JsonResponse(['error' => 'No subscription found'], 404);
        }

        if (!$subscription->getStripeSubscriptionId()) {
            return new JsonResponse(['error' => 'No Stripe subscription ID'], 400);
        }

        // Check if there's a scheduled change to cancel
        if ($subscription->getScheduledPlanType() === null) {
            return new JsonResponse(['error' => 'No scheduled plan change to cancel'], 400);
        }

        try {
            // Get current plan's price ID to revert to
            $currentPlanType = $subscription->getPlanType();
            $currentProduct = $em->getRepository(\App\Entity\Product::class)
                ->findOneBy(['slug' => $currentPlanType . '-subscription', 'isActive' => true]);

            if (!$currentProduct || !$currentProduct->getStripePriceId()) {
                return new JsonResponse(['error' => 'Current subscription plan not found'], 500);
            }

            // Revert Stripe subscription back to current plan price
            $stripe->scheduleSubscriptionPriceChange(
                $subscription->getStripeSubscriptionId(),
                $currentProduct->getStripePriceId()
            );

            // Clear scheduled change fields
            $subscription->setScheduledPlanType(null);
            $subscription->setScheduledStripePriceId(null);
            $subscription->setScheduledChangeEffectiveAt(null);
            $em->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Scheduled plan change has been cancelled. You will remain on your current plan.'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Failed to cancel scheduled change: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/api/settings/ble-auto-switch', name: 'settings_ble_auto_switch_get', methods: ['GET'])]
    public function getBleAutoSwitch(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        return new JsonResponse([
            'ble_auto_switch' => $user->getBleAutoSwitch()
        ]);
    }

    #[Route('/api/settings/ble-auto-switch', name: 'settings_ble_auto_switch_set', methods: ['POST'])]
    public function setBleAutoSwitch(
        Request $request,
        EntityManagerInterface $em
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['enabled'])) {
            return new JsonResponse(['error' => 'Missing "enabled" parameter'], 400);
        }

        $user->setBleAutoSwitch((bool) $data['enabled']);
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'ble_auto_switch' => $user->getBleAutoSwitch()
        ]);
    }

    #[Route('/api/settings/auto-update-firmware', name: 'settings_auto_update_firmware', methods: ['POST'])]
    public function setAutoUpdateFirmware(
        Request $request,
        EntityManagerInterface $em
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['enabled'])) {
            return new JsonResponse(['error' => 'Missing "enabled" parameter'], 400);
        }

        $user->setAutoUpdateFirmware((bool) $data['enabled']);
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'auto_update_firmware' => $user->isAutoUpdateFirmware()
        ]);
    }
}
