<?php

namespace App\Controller;

use App\Entity\Device;
use App\Entity\DeviceAccess;
use App\Entity\MicroData;
use App\Entity\Vehicle;
use App\Entity\AxleGroup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class DeviceController extends AbstractController
{
    #[Route('/device', name: 'device_list')]
    public function list(EntityManagerInterface $em): Response
    {
        $user = $this->getUser();

        // Get all devices the user has access to
        $qb = $em->getRepository(Device::class)->createQueryBuilder('d')
            ->leftJoin('d.deviceAccesses', 'da')
            ->leftJoin('d.deviceChannels', 'dc')
            ->addSelect('da', 'dc')
            ->where('d.soldTo = :user')
            ->orWhere('da.user = :user')
            ->setParameter('user', $user)
            ->orderBy('d.id', 'DESC');

        $devices = $qb->getQuery()->getResult();

        // Add status data for each device
        foreach ($devices as $device) {
            $this->addDeviceStatusData($em, $device);
        }

        return $this->render('device/list.html.twig', [
            'devices' => $devices,
        ]);
    }

    #[Route('/device/{id}', name: 'device_detail')]
    public function detail(int $id, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();

        // DEBUGGING - Remove after troubleshooting
        error_log("=== DEVICE ACCESS DEBUG ===");
        error_log("Requested Device ID: " . $id);
        error_log("Current User ID: " . ($user ? $user->getId() : 'NULL'));
        error_log("Current User Email: " . ($user ? $user->getEmail() : 'NULL'));

        // Get device with access control (same pattern as dashboard)
        $device = $this->getDeviceWithAccess($em, $id, $user);

        error_log("Device Found: " . ($device ? 'YES (ID: ' . $device->getId() . ')' : 'NO'));
        error_log("=========================");

        if (!$device) {
            throw $this->createNotFoundException('Device not found or access denied');
        }
        
        // Add latest sensor data and connection status (same as dashboard)
        $this->addDeviceStatusData($em, $device);
        
        // Get available axle groups for dropdown
        $axleGroups = $em->getRepository(AxleGroup::class)->findAll();
        
        return $this->render('device/index.html.twig', [
            'device' => $device,
            'axleGroups' => $axleGroups,
        ]);
    }

    #[Route('/device/{id}/api/live-data', name: 'device_api_live_data', methods: ['GET'])]
    public function getLiveData(int $id, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $device = $this->getDeviceWithAccess($em, $id, $user);

        if (!$device) {
            return new JsonResponse(['error' => 'Device not found'], 404);
        }

        // Get latest data with channel data
        $latestData = $em->getRepository(MicroData::class)
            ->createQueryBuilder('m')
            ->leftJoin('m.microDataChannels', 'mdc')
            ->addSelect('mdc')
            ->leftJoin('mdc.deviceChannel', 'dc')
            ->addSelect('dc')
            ->where('m.device = :device')
            ->setParameter('device', $device)
            ->orderBy('m.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$latestData) {
            return new JsonResponse(['error' => 'No data available'], 404);
        }

        $now = new \DateTime();
        $timestamp = $latestData->getTimestamp();
        $secondsDiff = $now->getTimestamp() - $timestamp->getTimestamp();

        // Build channel data array
        $channels = [];
        foreach ($latestData->getMicroDataChannels() as $microDataChannel) {
            $channels[] = [
                'channel_index' => $microDataChannel->getDeviceChannel()->getChannelIndex(),
                'air_pressure' => $microDataChannel->getAirPressure(),
                'weight' => $microDataChannel->getWeight(),
            ];
        }

        return new JsonResponse([
            'device_id' => $device->getId(),

            // Multi-channel data (new format)
            'channels' => $channels,

            // Legacy single-channel data (backward compatibility)
            'weight' => $latestData->getWeight(),
            'main_air_pressure' => $latestData->getMainAirPressure(),

            // Environmental data (shared)
            'atmospheric_pressure' => $latestData->getAtmosphericPressure(),
            'temperature' => $latestData->getTemperature(),
            'elevation' => $latestData->getElevation(),
            'gps_lat' => $latestData->getGpsLat(),
            'gps_lng' => $latestData->getGpsLng(),
            'gps_accuracy_m' => $latestData->getGpsAccuracyM(),
            'signal_strength' => $device->getSignalStrength(),

            // Timestamp and status
            'timestamp' => $timestamp->format('Y-m-d H:i:s'),
            'last_seen' => $this->formatTimeDifference($timestamp),
            'connection_status' => $this->getDeviceConnectionStatus($latestData),
            'seconds_since_last_data' => $secondsDiff,
        ]);
    }

    #[Route('/device/{id}/assign-vehicle', name: 'device_assign_vehicle', methods: ['POST'])]
    public function assignVehicle(int $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $device = $this->getDeviceWithAccess($em, $id, $user);
        
        if (!$device) {
            return new JsonResponse(['error' => 'Device not found'], 404);
        }
        
        $data = json_decode($request->getContent(), true);
        $vin = $data['vin'] ?? null;
        $axleGroupId = $data['axle_group_id'] ?? null;
        
        if (!$vin) {
            return new JsonResponse(['error' => 'VIN is required'], 400);
        }
        
        // Find or create vehicle
        $vehicle = $em->getRepository(Vehicle::class)->findOneBy(['vin' => $vin]);
        
        if (!$vehicle) {
            // Create new vehicle
            $vehicle = new Vehicle();
            $vehicle->setVin($vin);
            $vehicle->setYear($data['year'] ?? null);
            $vehicle->setMake($data['make'] ?? null);
            $vehicle->setModel($data['model'] ?? null);
            $vehicle->setLicensePlate($data['license_plate'] ?? null);
            $vehicle->setCreatedBy($user);
            
            // Set axle group if provided
            if ($axleGroupId) {
                $axleGroup = $em->getRepository(AxleGroup::class)->find($axleGroupId);
                if ($axleGroup) {
                    $vehicle->setAxleGroup($axleGroup);
                }
            }
            
            $em->persist($vehicle);
        } else {
            // Update existing vehicle if user owns it or no owner set
            if (!$vehicle->getCreatedBy() || $vehicle->getCreatedBy() === $user) {
                $vehicle->setYear($data['year'] ?? $vehicle->getYear());
                $vehicle->setMake($data['make'] ?? $vehicle->getMake());
                $vehicle->setModel($data['model'] ?? $vehicle->getModel());
                $vehicle->setLicensePlate($data['license_plate'] ?? $vehicle->getLicensePlate());
                $vehicle->setUpdatedBy($user);
                
                if ($axleGroupId) {
                    $axleGroup = $em->getRepository(AxleGroup::class)->find($axleGroupId);
                    if ($axleGroup) {
                        $vehicle->setAxleGroup($axleGroup);
                    }
                }
            }
        }
        
        // Assign device to vehicle
        $device->setVehicle($vehicle);
        
        $em->flush();
        
        return new JsonResponse([
            'success' => true,
            'vehicle' => [
                'id' => $vehicle->getId(),
                'display' => $vehicle->__toString(),
                'vin' => $vehicle->getVin(),
                'axle_group' => $vehicle->getAxleGroup()?->getLabel(),
            ]
        ]);
    }

    #[Route('/device/{id}/unassign-vehicle', name: 'device_unassign_vehicle', methods: ['POST'])]
    public function unassignVehicle(int $id, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $device = $this->getDeviceWithAccess($em, $id, $user);
        
        if (!$device) {
            return new JsonResponse(['error' => 'Device not found'], 404);
        }
        
        $device->setVehicle(null);
        $em->flush();
        
        return new JsonResponse(['success' => true]);
    }

    #[Route('/device/{id}/search-vehicles', name: 'device_search_vehicles', methods: ['GET'])]
    public function searchVehicles(int $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $device = $this->getDeviceWithAccess($em, $id, $user);
        
        if (!$device) {
            return new JsonResponse(['error' => 'Device not found'], 404);
        }
        
        $query = $request->query->get('q', '');
        
        if (strlen($query) < 3) {
            return new JsonResponse(['vehicles' => []]);
        }
        
        // Search vehicles by VIN, make, model, or license plate
        $vehicles = $em->getRepository(Vehicle::class)
            ->createQueryBuilder('v')
            ->where('v.vin LIKE :query OR v.make LIKE :query OR v.model LIKE :query OR v.license_plate LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
        
        $results = [];
        foreach ($vehicles as $vehicle) {
            $results[] = [
                'id' => $vehicle->getId(),
                'vin' => $vehicle->getVin(),
                'display' => $vehicle->__toString(),
                'year' => $vehicle->getYear(),
                'make' => $vehicle->getMake(),
                'model' => $vehicle->getModel(),
                'license_plate' => $vehicle->getLicensePlate(),
                'axle_group' => $vehicle->getAxleGroup()?->getLabel(),
                'axle_group_id' => $vehicle->getAxleGroup()?->getId(),
            ];
        }
        
        return new JsonResponse(['vehicles' => $results]);
    }

    #[Route('/device/{id}/restart', name: 'device_restart', methods: ['POST'])]
    public function restartDevice(int $id, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $device = $this->getDeviceWithAccess($em, $id, $user);
        
        if (!$device) {
            return new JsonResponse(['error' => 'Device not found'], 404);
        }
        
        // TODO: Implement actual device restart via API call to ESP32
        // For now, just return success
        
        return new JsonResponse([
            'success' => true,
            'message' => 'Restart command sent to device'
        ]);
    }

    #[Route('/device/{id}/factory-reset', name: 'device_factory_reset', methods: ['POST'])]
    public function factoryResetDevice(int $id, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $device = $this->getDeviceWithAccess($em, $id, $user);
        
        if (!$device) {
            return new JsonResponse(['error' => 'Device not found'], 404);
        }
        
        // TODO: Implement actual factory reset via API call to ESP32
        // For now, just return success
        
        return new JsonResponse([
            'success' => true,
            'message' => 'Factory reset command sent to device'
        ]);
    }

    #[Route('/device/{id}/update-firmware', name: 'device_update_firmware', methods: ['POST'])]
    public function updateFirmware(int $id, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $device = $this->getDeviceWithAccess($em, $id, $user);

        if (!$device) {
            return new JsonResponse(['error' => 'Device not found'], 404);
        }

        // TODO: Implement actual firmware update via API call to ESP32
        // For now, just return success

        return new JsonResponse([
            'success' => true,
            'message' => 'Firmware update initiated'
        ]);
    }

    #[Route('/device/{id}/forget', name: 'device_forget', methods: ['POST'])]
    public function forgetDevice(int $id, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $device = $this->getDeviceWithAccess($em, $id, $user);

        if (!$device) {
            return new JsonResponse(['error' => 'Device not found'], 404);
        }

        // Check if user is the owner
        if ($device->getSoldTo() === $user) {
            // User is owner - set soldTo to null (release ownership)
            // Next user who connects will become the new owner
            $device->setSoldTo(null);

            // Also remove any DeviceAccess records for this user
            $accessRecords = $em->getRepository(DeviceAccess::class)
                ->createQueryBuilder('a')
                ->where('a.device = :device')
                ->andWhere('a.user = :user')
                ->setParameter('device', $device)
                ->setParameter('user', $user)
                ->getQuery()
                ->getResult();

            foreach ($accessRecords as $accessRecord) {
                $em->remove($accessRecord);
            }

            $em->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Device ownership released. Device is now available for claiming.'
            ]);
        } else {
            // User is not owner - just remove their DeviceAccess record
            $accessRecord = $em->getRepository(DeviceAccess::class)
                ->createQueryBuilder('a')
                ->where('a.device = :device')
                ->andWhere('a.user = :user')
                ->setParameter('device', $device)
                ->setParameter('user', $user)
                ->getQuery()
                ->getOneOrNullResult();

            if (!$accessRecord) {
                return new JsonResponse(['error' => 'No access record found'], 404);
            }

            $em->remove($accessRecord);
            $em->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Device access removed successfully'
            ]);
        }
    }

    private function getDeviceWithAccess(EntityManagerInterface $em, int $deviceId, $user): ?Device
    {
        error_log("  Checking DeviceAccess records...");
        // First try to find device through access records (same as dashboard)
        $accessRecord = $em->getRepository(DeviceAccess::class)
            ->createQueryBuilder('a')
            ->leftJoin('a.device', 'd')
            ->addSelect('d')
            ->where('a.user = :user')
            ->andWhere('d.id = :deviceId')
            ->andWhere('a.isActive = true')
            ->setParameter('user', $user)
            ->setParameter('deviceId', $deviceId)
            ->getQuery()
            ->getOneOrNullResult();

        if ($accessRecord) {
            error_log("  Found via DeviceAccess record!");
            return $accessRecord->getDevice();
        }

        error_log("  No DeviceAccess record. Checking soldTo field...");
        // Try purchased/claimed devices (soldTo)
        $device = $em->getRepository(Device::class)
            ->createQueryBuilder('d')
            ->where('d.soldTo = :user')
            ->andWhere('d.id = :deviceId')
            ->setParameter('user', $user)
            ->setParameter('deviceId', $deviceId)
            ->getQuery()
            ->getOneOrNullResult();

        if ($device) {
            error_log("  Found via soldTo field!");
        } else {
            error_log("  Not found via soldTo either.");
        }

        return $device;
    }

    private function addDeviceStatusData(EntityManagerInterface $em, Device $device): void
    {
        // Get the most recent data by ID (same as dashboard)
        $latestData = $em->getRepository(MicroData::class)
            ->createQueryBuilder('m')
            ->where('m.device = :device')
            ->setParameter('device', $device)
            ->orderBy('m.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
            
        $device->setLatestMicroData($latestData);
        $device->setConnectionStatus($this->getDeviceConnectionStatus($latestData));
        $device->setLastSeenText($this->getLastSeenText($latestData));
    }

    private function getDeviceConnectionStatus($latestData): string
    {
        if (!$latestData) {
            return 'no-data';
        }

        $now = new \DateTime();
        $timestamp = $latestData->getTimestamp();
        $secondsDiff = $now->getTimestamp() - $timestamp->getTimestamp();

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

    #[Route('/device/{id}/toggle-channel-2', name: 'device_toggle_channel_2', methods: ['POST'])]
    public function toggleChannel2(int $id, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $device = $this->getDeviceWithAccess($em, $id, $user);

        if (!$device) {
            return new JsonResponse(['error' => 'Device not found'], 404);
        }

        $channel2 = $device->getChannel(2);
        if (!$channel2) {
            return new JsonResponse(['error' => 'Channel 2 not found'], 404);
        }

        // Toggle the enabled state
        $newState = !$channel2->isEnabled();
        $channel2->setEnabled($newState);
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'enabled' => $newState,
            'label' => $channel2->getDisplayLabel()
        ]);
    }

    #[Route('/device/{id}/toggle-channel/{channelIndex}', name: 'device_toggle_channel', methods: ['POST'])]
    public function toggleChannel(int $id, int $channelIndex, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $device = $this->getDeviceWithAccess($em, $id, $user);

        if (!$device) {
            return new JsonResponse(['error' => 'Device not found'], 404);
        }

        $channel = $device->getChannel($channelIndex);
        if (!$channel) {
            return new JsonResponse(['error' => 'Channel not found'], 404);
        }

        // Toggle the enabled state
        $newState = !$channel->isEnabled();
        $channel->setEnabled($newState);
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'enabled' => $newState,
            'channelIndex' => $channelIndex,
            'label' => $channel->getDisplayLabel()
        ]);
    }

    #[Route('/device/{id}/update-virtual-steer', name: 'device_update_virtual_steer', methods: ['POST'])]
    public function updateVirtualSteer(int $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $device = $this->getDeviceWithAccess($em, $id, $user);

        if (!$device) {
            return new JsonResponse(['error' => 'Device not found'], 404);
        }

        $data = json_decode($request->getContent(), true);

        $hasVirtualSteer = ($data['has_virtual_steer'] ?? '0') === '1';
        $wheelbase = !empty($data['wheelbase']) ? (float)$data['wheelbase'] : null;
        $kingpinDistance = !empty($data['kingpin_distance']) ? (float)$data['kingpin_distance'] : null;

        $device->setHasVirtualSteer($hasVirtualSteer);
        $device->setWheelbase($wheelbase);
        $device->setKingpinDistance($kingpinDistance);

        $em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Virtual steer settings updated successfully',
            'has_virtual_steer' => $hasVirtualSteer,
            'wheelbase' => $wheelbase,
            'kingpin_distance' => $kingpinDistance
        ]);
    }

    #[Route('/device/{id}/reorder-channels', name: 'device_reorder_channels', methods: ['POST'])]
    public function reorderChannels(int $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $device = $this->getDeviceWithAccess($em, $id, $user);

        if (!$device) {
            return new JsonResponse(['error' => 'Device not found or access denied'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $order = $data['order'] ?? [];

        if (empty($order)) {
            return new JsonResponse(['error' => 'No order data provided'], 400);
        }

        // Update display order for each channel
        foreach ($order as $item) {
            $displayOrder = $item['displayOrder'] ?? null;
            $type = $item['type'] ?? null;

            if ($displayOrder === null || $type === null) {
                continue;
            }

            if ($type === 'channel' && isset($item['id'])) {
                // Update regular channel
                $channel = $em->getRepository(\App\Entity\DeviceChannel::class)->find($item['id']);

                if ($channel && $channel->getDevice() === $device) {
                    $channel->setDisplayOrder($displayOrder);
                }
            } elseif ($type === 'virtual-steer') {
                // Save virtual steer display order on the device
                $device->setVirtualSteerDisplayOrder($displayOrder);
            }
        }

        $em->flush();

        return new JsonResponse(['success' => true, 'message' => 'Channel order saved']);
    }
}