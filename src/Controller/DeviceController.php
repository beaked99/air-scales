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
    #[Route('/device/{id}', name: 'device_detail')]
    public function detail(int $id, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        
        // Get device with access control (same pattern as dashboard)
        $device = $this->getDeviceWithAccess($em, $id, $user);
        
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
        
        // Get latest data
        $latestData = $em->getRepository(MicroData::class)
            ->createQueryBuilder('m')
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
        
        return new JsonResponse([
            'device_id' => $device->getId(),
            'weight' => $latestData->getWeight(),
            'main_air_pressure' => $latestData->getMainAirPressure(),
            'atmospheric_pressure' => $latestData->getAtmosphericPressure(),
            'temperature' => $latestData->getTemperature(),
            'elevation' => $latestData->getElevation(),
            'gps_lat' => $latestData->getGpsLat(),
            'gps_lng' => $latestData->getGpsLng(),
            'signal_strength' => $device->getSignalStrength(),
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

    private function getDeviceWithAccess(EntityManagerInterface $em, int $deviceId, $user): ?Device
    {
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
            return $accessRecord->getDevice();
        }

        // Try purchased devices
        $device = $em->getRepository(Device::class)
            ->createQueryBuilder('d')
            ->where('d.soldTo = :user')
            ->andWhere('d.id = :deviceId')
            ->setParameter('user', $user)
            ->setParameter('deviceId', $deviceId)
            ->getQuery()
            ->getOneOrNullResult();

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
}