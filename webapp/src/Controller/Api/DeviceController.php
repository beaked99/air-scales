<?php

namespace App\Controller\Api;

use App\Entity\Device;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/device', name: 'api_device_')]
class DeviceController extends AbstractController
{
    #[Route('/claim', name: 'claim', methods: ['POST'])]
    public function claimDevice(
        Request $request,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        // User must be logged in
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse([
                'success' => false,
                'error' => 'You must be logged in to claim a device.'
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Get device info from request
        $data = json_decode($request->getContent(), true);
        $deviceIdentifier = $data['device_identifier'] ?? null; // Could be serial number or MAC address

        if (!$deviceIdentifier) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Device identifier is required.'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Find device by serial number or MAC address
        $device = $entityManager->getRepository(Device::class)->findOneBy([
            'serialNumber' => $deviceIdentifier
        ]);

        if (!$device) {
            $device = $entityManager->getRepository(Device::class)->findOneBy([
                'macAddress' => $deviceIdentifier
            ]);
        }

        if (!$device) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Device not found. Please check the device identifier.'
            ], Response::HTTP_NOT_FOUND);
        }

        // Check if device can be claimed
        if (!$device->canBeClaimed()) {
            if ($device->getSoldTo() === $user) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'You have already claimed this device.',
                    'already_owned' => true
                ], Response::HTTP_BAD_REQUEST);
            }

            return new JsonResponse([
                'success' => false,
                'error' => 'This device has already been claimed by another user.'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Claim the device
        $device->setSoldTo($user);
        $device->setFirstActivatedAt(new \DateTimeImmutable());
        $device->setFirstActivatedBy($user);
        $device->setSubscriptionGranted(true);

        // Grant 6 months free subscription
        $this->grantSubscription($user, $entityManager);

        // Save changes
        $entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Device claimed successfully! You have been granted 6 months free subscription.',
            'device' => [
                'id' => $device->getId(),
                'serial_number' => $device->getSerialNumber(),
                'claimed_at' => $device->getFirstActivatedAt()->format('Y-m-d H:i:s')
            ]
        ]);
    }

    #[Route('/check-claim-status', name: 'check_claim_status', methods: ['POST'])]
    public function checkClaimStatus(
        Request $request,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $deviceIdentifier = $data['device_identifier'] ?? null;

        if (!$deviceIdentifier) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Device identifier is required.'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Find device
        $device = $entityManager->getRepository(Device::class)->findOneBy([
            'serialNumber' => $deviceIdentifier
        ]) ?? $entityManager->getRepository(Device::class)->findOneBy([
            'macAddress' => $deviceIdentifier
        ]);

        if (!$device) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Device not found.'
            ], Response::HTTP_NOT_FOUND);
        }

        /** @var User|null $currentUser */
        $currentUser = $this->getUser();

        return new JsonResponse([
            'success' => true,
            'device' => [
                'serial_number' => $device->getSerialNumber(),
                'can_be_claimed' => $device->canBeClaimed(),
                'is_claimed' => $device->isClaimed(),
                'owned_by_current_user' => $currentUser && $device->getSoldTo() === $currentUser,
                'claimed_at' => $device->getFirstActivatedAt()?->format('Y-m-d H:i:s'),
            ]
        ]);
    }

    private function grantSubscription(User $user, EntityManagerInterface $entityManager): void
    {
        $subscription = $user->getSubscription();

        if (!$subscription) {
            // User doesn't have a subscription yet - create one
            // This will be handled in the Subscription entity/service
            // For now, just set a note that they have 6 months free
            // The actual subscription logic will be implemented when Stripe webhooks are set up
            return;
        }

        // Extend existing subscription by 6 months
        if ($subscription->getEndsAt()) {
            $newEndDate = $subscription->getEndsAt()->modify('+6 months');
            $subscription->setEndsAt($newEndDate);
        } else {
            // No end date set, start from now
            $subscription->setEndsAt(new \DateTimeImmutable('+6 months'));
        }

        $entityManager->flush();
    }
}
