<?php
namespace App\Controller;

use App\Form\VehicleType;
use Symfony\Component\HttpFoundation\Request;
use App\Entity\Device;
use App\Entity\DeviceAccess;
use App\Entity\Vehicle;
use App\Entity\UserVehicleOrder;
use App\Entity\UserConnectedVehicle;

use Symfony\Component\HttpFoundation\JsonResponse;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(EntityManagerInterface $em): Response
    {
        $user = $this->getUser();

        // 1. Devices the user connected to
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
            $device = $record->getDevice();
            $userDevices[$device->getId()] = $device;
        }

        // 2. Devices the user purchased
        $purchasedDevices = $em->getRepository(Device::class)
            ->findBy(['soldTo' => $user]);

        foreach ($purchasedDevices as $device) {
            $userDevices[$device->getId()] = $device; // ✅ merge properly here
        }
        // Get vehicles for this user, ordered by drag-and-drop preference
        $order = $em->getRepository(UserVehicleOrder::class)
            ->findBy(['user' => $user], ['position' => 'ASC']);

        $vehicleSet = [];

        foreach ($order as $vOrder) {
            $vehicle = $vOrder->getVehicle();
            if ($vehicle) {
                $vehicleSet[$vehicle->getId()] = $vehicle;
            }
        }

        // Also add vehicles seen via device connection (if not already in order)
        foreach ($userDevices as $device) {
            if ($device->getVehicle()) {
                $vehicleSet[$device->getVehicle()->getId()] = $device->getVehicle();
            }
        }
        $connections = $em->getRepository(UserConnectedVehicle::class)
            ->findBy(['user' => $user]);

        $connectedMap = [];
        foreach ($connections as $conn) {
            $vehicle = $conn->getVehicle();
            if ($vehicle) {
                $connectedMap[$vehicle->getId()] = $conn->isConnected();
            }
        }

        return $this->render('dashboard/index.html.twig', [
            'devices' => $userDevices,
            'accessRecords' => $accessRecords,
            'vehicles' => $vehicleSet,
            'connectedMap' => $connectedMap,
        ]);
    }

    #[Route('/dashboard/device/unlink/{id}', name: 'unlink_device')]
    public function unlink(Device $device, EntityManagerInterface $em): Response
    {
        $access = $em->getRepository(DeviceAccess::class)
            ->findOneBy(['user' => $this->getUser(), 'device' => $device]);

        if ($access && $access->isActive()) {
            $access->setIsActive(false);
            $em->flush();
        }

        return $this->redirectToRoute('app_dashboard');
    }
    #[Route('/dashboard/vehicle/reorder', name: 'vehicle_sort_user', methods: ['POST'])]
    public function reorderVehicles(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $ids = $data['order'] ?? [];

        foreach ($ids as $position => $vehicleId) {
            $vehicle = $em->getRepository(Vehicle::class)->find($vehicleId);
            if (!$vehicle) continue;

            $order = $em->getRepository(UserVehicleOrder::class)
                ->findOneBy(['user' => $this->getUser(), 'vehicle' => $vehicle]);

            if (!$order) {
                $order = new UserVehicleOrder();
                $order->setUser($this->getUser());
                $order->setVehicle($vehicle);
            }

            $order->setPosition($position);
            $em->persist($order);
        }

        $em->flush();
        return new JsonResponse(['status' => 'ok']);
    }
    #[Route('/dashboard/api/devices/live-data', name: 'dashboard_api_devices_live_data', methods: ['GET'])]
    public function getAllDevicesLiveData(EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        
        // Get all devices user has access to (same logic as your index method)
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
            $device = $record->getDevice();
            $userDevices[$device->getId()] = $device;
        }

        // Add purchased devices
        $purchasedDevices = $em->getRepository(Device::class)
            ->findBy(['soldTo' => $user]);

        foreach ($purchasedDevices as $device) {
            $userDevices[$device->getId()] = $device;
        }
        
        $liveData = [];
        $now = new \DateTime();
        
        foreach ($userDevices as $device) {
            // Get latest sensor data for each device
            $latestData = $em->getRepository(\App\Entity\MicroData::class)
                ->createQueryBuilder('m')
                ->where('m.device = :device')
                ->setParameter('device', $device)
                ->orderBy('m.id', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
            
            if ($latestData) {
                $timestamp = $latestData->getTimestamp();
                $secondsDiff = $now->getTimestamp() - $timestamp->getTimestamp();
                
                // Determine actual status based on how recent the data is
                $status = 'offline';
                $lastSeen = $this->formatTimeDifference($timestamp);
                
                if ($secondsDiff <= 120) { // 2 minutes = online (for WiFi direct connections)
                    $status = 'online';
                    $lastSeen = 'just now';
                } elseif ($secondsDiff <= 300) { // 5 minutes = recent (for BLE via phone)
                    $status = 'recent';
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
                    'status' => $status, // PROPER status based on timing
                    'last_seen' => $lastSeen,
                    'micro_data_id' => $latestData->getId(),
                    'seconds_since_last_data' => $secondsDiff // For debugging
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

    private function formatTimeDifference(\DateTimeInterface $timestamp): string
    {
        $now = new \DateTime();
        $secondsDiff = $now->getTimestamp() - $timestamp->getTimestamp();
        
        if ($secondsDiff < 0) {
            return 'in the future';
        }
        
        if ($secondsDiff < 60) {
            return 'just now';
        }
        
        $days = floor($secondsDiff / 86400);
        $hours = floor(($secondsDiff % 86400) / 3600);
        $minutes = floor(($secondsDiff % 3600) / 60);
        
        if ($days > 0) {
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } elseif ($hours > 0) {
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($minutes > 0) {
            return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
        } else {
            return 'just now';
        }
    }
}
