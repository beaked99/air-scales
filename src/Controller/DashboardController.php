<?php
// src/Controller/DashboardController.php
namespace App\Controller;

use App\Entity\Device;
use App\Entity\DeviceAccess;
use App\Entity\MicroData;
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
            
            // Debug: Log device info for device 11 specifically
            if ($device->getId() == 11 && $latestData) {
                error_log("=== DEVICE 11 DEBUG ===");
                error_log("Device 11 timestamp: " . $latestData->getTimestamp()->format('Y-m-d H:i:s'));
                error_log("Current PHP time: " . (new \DateTime())->format('Y-m-d H:i:s'));
                error_log("Connection status: " . $device->connectionStatus);
                error_log("Last seen text: " . $device->lastSeenText);
                error_log("======================");
            }
            
            // Debug: Log device info
            if ($latestData) {
                error_log("Device " . $device->getSerialNumber() . " - Timestamp: " . $latestData->getTimestamp()->format('Y-m-d H:i:s'));
            }
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

        // DEBUG: Log the actual calculation
        error_log("=== CONNECTION STATUS DEBUG ===");
        error_log("Device timestamp: " . $timestamp->format('Y-m-d H:i:s'));
        error_log("Current time: " . $now->format('Y-m-d H:i:s'));
        error_log("Seconds difference: " . $secondsDiff);
        error_log("Minutes difference: " . round($secondsDiff / 60, 2));
        error_log("Days difference: " . round($secondsDiff / 86400, 2));

        // Handle corrupted future timestamps
        if ($secondsDiff < 0) {
            error_log("Status: CORRUPTED DATA (negative time difference)");
            return 'corrupted';
        }

        if ($secondsDiff <= 120) { // 2 minutes
            error_log("Status: CONNECTED (≤120 seconds)");
            return 'connected';
        } elseif ($secondsDiff <= 300) { // 5 minutes
            error_log("Status: RECENT (≤300 seconds)");
            return 'recent';
        } else {
            error_log("Status: OFFLINE (>300 seconds)");
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

        // DEBUG: Log the calculation
        error_log("=== LAST SEEN DEBUG ===");
        error_log("Timestamp: " . $timestamp->format('Y-m-d H:i:s'));
        error_log("Seconds diff: " . $secondsDiff);

        if ($secondsDiff < 120) {
            error_log("timeAgo: {$secondsDiff} seconds ago (under 2 minutes)");
            return $secondsDiff . ' sec ago';
        } elseif ($secondsDiff < 180 * 60) { // less than 180 minutes
            $minutes = floor($secondsDiff / 60);
            error_log("timeAgo: {$minutes} minutes ago (under 3 hours)");
            return $minutes . ' min ago';
        } elseif ($secondsDiff < 96 * 3600) { // less than 96 hours
            $hours = floor($secondsDiff / 3600);
            error_log("timeAgo: {$hours} hours ago (under 4 days)");
            return $hours . ' hour(s) ago';
        } else {
            $days = floor($secondsDiff / 86400);
            error_log("timeAgo: {$days} days ago (over 4 days)");
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

    private function formatTimeDifference($timestamp): string
    {
        $now = new \DateTime();
        $secondsDiff = $now->getTimestamp() - $timestamp->getTimestamp();

        if ($secondsDiff <= 60) {
            return 'just now';
        } elseif ($secondsDiff < 3600) { // Less than 1 hour
            $minutes = floor($secondsDiff / 60);
            return $minutes . ' min ago';
        } elseif ($secondsDiff < 86400) { // Less than 1 day
            $hours = floor($secondsDiff / 3600);
            return $hours . ' hour(s) ago';
        } else {
            $days = floor($secondsDiff / 86400);
            return $days . ' day(s) ago';
        }
    }
}