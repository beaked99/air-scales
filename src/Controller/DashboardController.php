<?php
// src/Controller/DashboardController.php
namespace App\Controller;

use App\Entity\Device;
use App\Entity\DeviceAccess;
use App\Entity\MicroData;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        
        // Get user's devices with latest data
        $userDevices = $this->getUserDevicesWithData($em, $user);
        
        // Calculate totals
        $totalWeight = $this->calculateTotalWeight($userDevices);
        $hasActiveSubscription = $this->checkSubscriptionStatus($user);
        $hasSetupConfiguration = $this->checkSetupStatus($userDevices);
        
        return $this->render('dashboard/index.html.twig', [
            'devices' => $userDevices,
            'totalWeight' => $totalWeight,
            'hasActiveSubscription' => $hasActiveSubscription,
            'hasSetupConfiguration' => $hasSetupConfiguration,
        ]);
    }

    private function getUserDevicesWithData(EntityManagerInterface $em, $user): array
    {
        // Get devices through access records
        $accessRecords = $em->getRepository(DeviceAccess::class)
            ->createQueryBuilder('a')
            ->leftJoin('a.device', 'd')
            ->addSelect('d')
            ->where('a.user = :user')
            ->andWhere('a.isActive = true')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        $userDevices = [];
        foreach ($accessRecords as $record) {
            $userDevices[$record->getDevice()->getId()] = $record->getDevice();
        }

        // Add purchased devices
        $purchasedDevices = $em->getRepository(Device::class)->findBy(['soldTo' => $user]);
        foreach ($purchasedDevices as $device) {
            $userDevices[$device->getId()] = $device;
        }

        // Add latest sensor data and connection status to each device
        foreach ($userDevices as $device) {
            // Get the most recent data by ID (most efficient and reliable)
            $latestData = $em->getRepository(MicroData::class)
                ->createQueryBuilder('m')
                ->where('m.device = :device')
                ->setParameter('device', $device)
                ->orderBy('m.id', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
                
            // Add properties we can access in Twig using setters
            $device->setLatestMicroData($latestData);
            $device->setConnectionStatus($this->getDeviceConnectionStatus($latestData));
            $device->setLastSeenText($this->getLastSeenText($latestData));
        }
        
        return array_values($userDevices);
    }

    private function getDeviceConnectionStatus($latestData): string
    {
        if (!$latestData) {
            return 'no-data';
        }

        $now = new \DateTime();
        $timestamp = $latestData->getTimestamp();
        $secondsDiff = $now->getTimestamp() - $timestamp->getTimestamp();

        // Handle corrupted future timestamps
        if ($secondsDiff < 0) {
            return 'corrupted';
        }

        if ($secondsDiff <= 120) { // 2 minutes
            return 'connected';
        } elseif ($secondsDiff <= 300) { // 5 minutes
            return 'recent';
        } else {
            return 'offline';
        }
    }

    private function getLastSeenText($latestData): string
    {
        if (!$latestData) {
            return 'No Data';
        }

        $now = new \DateTime();
        $timestamp = $latestData->getTimestamp();
        $secondsDiff = $now->getTimestamp() - $timestamp->getTimestamp();

        if ($secondsDiff < 120) {
            return $secondsDiff . ' sec ago';
        } elseif ($secondsDiff < 180 * 60) { // less than 180 minutes
            $minutes = floor($secondsDiff / 60);
            return $minutes . ' min ago';
        } elseif ($secondsDiff < 96 * 3600) { // less than 96 hours
            $hours = floor($secondsDiff / 3600);
            return $hours . ' hour(s) ago';
        } else {
            $days = floor($secondsDiff / 86400);
            return $days . ' day(s) ago';
        }
    }

    private function calculateTotalWeight(array $devices): array
    {
        $totalWeight = 0;
        $deviceCount = 0;
        $hasValidData = false;

        foreach ($devices as $device) {
            if ($device->getLatestMicroData()) {
                $totalWeight += $device->getLatestMicroData()->getWeight();
                $deviceCount++;
                $hasValidData = true;
            }
        }

        // Calculate error margin based on number of calibrations (placeholder logic)
        $errorMargin = $hasValidData ? max(200, $deviceCount * 50) : 0;

        return [
            'total' => $totalWeight,
            'error_margin' => $errorMargin,
            'has_data' => $hasValidData,
            'device_count' => $deviceCount
        ];
    }

    private function checkSubscriptionStatus($user): bool
    {
        // TODO: Implement actual subscription check
        // For now, return false to show the warning
        return $user->getStripeCustomerId() !== null;
    }

    private function checkSetupStatus(array $devices): bool
    {
        // Consider setup complete if user has at least one device with data
        foreach ($devices as $device) {
            if ($device->getLatestMicroData()) {
                return true;
            }
        }
        return false;
    }

    #[Route('/dashboard/api/devices/live-data', name: 'dashboard_api_devices_live_data', methods: ['GET'])]
    public function getAllDevicesLiveData(EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        
        // Get user's accessible devices
        $userDevices = $this->getUserDevicesWithData($em, $user);
        
        $liveData = [];
        $now = new \DateTime();
        
        foreach ($userDevices as $device) {
            if ($device->getLatestMicroData()) {
                $latestData = $device->getLatestMicroData();
                $timestamp = $latestData->getTimestamp();
                $secondsDiff = $now->getTimestamp() - $timestamp->getTimestamp();
                
                // Enhanced status logic 
                $lastSeen = $this->formatTimeDifference($timestamp);

                if ($secondsDiff < 120) {
                    $status = 'online'; // green
                } elseif ($secondsDiff < 180 * 60) {
                    $status = 'recent'; // orange
                } elseif ($secondsDiff < 96 * 3600) {
                    $status = 'offline'; // red
                } else {
                    $status = 'old'; // optional gray or faded
                }
                
                $liveData[] = [
                    'device_id' => $device->getId(),
                    'device_name' => $device->getSerialNumber() ?: ('Device #' . $device->getId()),
                    'mac_address' => $device->getMacAddress(),
                    'weight' => $latestData->getWeight(),
                    'main_air_pressure' => $latestData->getMainAirPressure(),
                    'temperature' => $latestData->getTemperature(),
                    'timestamp' => $timestamp->format('Y-m-d H:i:s'),
                    'vehicle' => $device->getVehicle() ? $device->getVehicle()->__toString() : null,
                    'status' => $status,
                    'last_seen' => $lastSeen,
                    'micro_data_id' => $latestData->getId(),
                    'seconds_since_last_data' => $secondsDiff,
                ];
            }
        }
        
        return new JsonResponse([
            'devices' => $liveData,
            'total_weight' => array_sum(array_column($liveData, 'weight')),
            'device_count' => count($liveData),
            'last_updated' => $now->format('Y-m-d H:i:s')
        ]);
    }

    #[Route('/api/current-user', name: 'api_current_user', methods: ['GET'])]
    public function getCurrentUser(): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        return new JsonResponse([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'name' => $user->getFullName(),
        ]);
    }

    private function formatTimeDifference($timestamp): string
    {
        $now = new \DateTime();
        $secondsDiff = $now->getTimestamp() - $timestamp->getTimestamp();

        if ($secondsDiff < 60) {
            return 'Connected';
        } elseif ($secondsDiff < 180 * 60) {
            $minutes = floor($secondsDiff / 60);
            return $minutes . ' min ago';
        } elseif ($secondsDiff < 96 * 3600) {
            $hours = floor($secondsDiff / 3600);
            return $hours . ' hours ago';
        } else {
            $days = floor($secondsDiff / 86400);
            return $days . ' days ago';
        }
    }
}