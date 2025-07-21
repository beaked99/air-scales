<?php
namespace App\Controller;

use App\Form\VehicleType;
use Symfony\Component\HttpFoundation\Request;
use App\Entity\Device;
use App\Entity\DeviceAccess;
use App\Entity\Vehicle;
use App\Entity\UserVehicleOrder;
use App\Entity\UserConnectedVehicle;
use App\Entity\TruckConfiguration;
use App\Entity\DeviceRole;
use App\Entity\User;

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
        
        // Get user's accessible devices
        $userDevices = $this->getUserDevices($em, $user);
        
        $liveData = [];
        $now = new \DateTime();
        
        foreach ($userDevices as $device) {
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
                
                // Enhanced status logic including mesh status
                $status = 'offline';
                $lastSeen = $this->formatTimeDifference($timestamp);
                
                if ($secondsDiff <= 120) {
                    $status = 'online';
                    $lastSeen = 'just now';
                } elseif ($secondsDiff <= 300) {
                    $status = 'recent';
                }
                
                // Add mesh information
                $meshInfo = '';
                if (method_exists($device, 'getCurrentRole') && $device->getCurrentRole()) {
                    $meshInfo = ' (' . strtoupper($device->getCurrentRole()) . ')';
                    
                    // If this is a master device, add slave count
                    if (method_exists($device, 'isMeshMaster') && $device->isMeshMaster()) {
                        $slaveCount = count($device->getConnectedSlaves() ?: []);
                        $meshInfo .= " +{$slaveCount} slaves";
                    }
                }
                
                $liveData[] = [
                    'device_id' => $device->getId(),
                    'device_name' => ($device->getSerialNumber() ?: ('Device #' . $device->getId())) . $meshInfo,
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
                    // Mesh information (only if methods exist)
                    'mesh_role' => method_exists($device, 'getCurrentRole') ? $device->getCurrentRole() : null,
                    'is_mesh_master' => method_exists($device, 'isMeshMaster') ? $device->isMeshMaster() : false,
                    'connected_slaves' => method_exists($device, 'getConnectedSlaves') ? ($device->getConnectedSlaves() ?: []) : [],
                    'signal_strength' => method_exists($device, 'getSignalStrength') ? $device->getSignalStrength() : null,
                    'mesh_last_activity' => method_exists($device, 'getLastMeshActivity') ? $device->getLastMeshActivity()?->format('Y-m-d H:i:s') : null
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

    #[Route('/dashboard/api/mesh-devices', name: 'dashboard_api_mesh_devices')]
    public function getMeshDevices(EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        
        // Get user's accessible devices
        $userDevices = $this->getUserDevices($em, $user);
        
        // Find mesh networks involving user's devices
        $meshNetworks = [];
        
        foreach ($userDevices as $device) {
            // Skip if device doesn't have mesh methods yet
            if (!method_exists($device, 'getCurrentRole')) {
                continue;
            }
            
            $networkDevices = [];
            
            if (method_exists($device, 'isMeshMaster') && $device->isMeshMaster()) {
                // Get all slaves for this master
                $slaves = $em->getRepository(Device::class)->findBy([
                    'masterDeviceMac' => $device->getMacAddress()
                ]);
                $networkDevices[] = $device; // Include master
                $networkDevices = array_merge($networkDevices, $slaves);
            } elseif (method_exists($device, 'isMeshSlave') && $device->isMeshSlave()) {
                // Get master and other slaves in the network
                $masterMac = $device->getMasterDeviceMac();
                if ($masterMac) {
                    $master = $em->getRepository(Device::class)->findOneBy(['macAddress' => $masterMac]);
                    if ($master) {
                        $networkDevices[] = $master;
                        $slaves = $em->getRepository(Device::class)->findBy([
                            'masterDeviceMac' => $masterMac
                        ]);
                        $networkDevices = array_merge($networkDevices, $slaves);
                    }
                }
            } else {
                // Single device not in mesh
                $networkDevices[] = $device;
            }
            
            // Deduplicate devices across networks
            $networkKey = (method_exists($device, 'isMeshMaster') && $device->isMeshMaster()) ? 
                         $device->getMacAddress() : 
                         (method_exists($device, 'getMasterDeviceMac') ? $device->getMasterDeviceMac() : $device->getMacAddress());
            
            if (!isset($meshNetworks[$networkKey])) {
                $meshNetworks[$networkKey] = $networkDevices;
            }
        }
        
        // Format response
        $response = [];
        foreach ($meshNetworks as $networkKey => $devices) {
            $network = [
                'network_id' => $networkKey,
                'devices' => [],
                'total_weight' => 0,
                'is_active' => false
            ];
            
            foreach ($devices as $device) {
                $isActive = method_exists($device, 'getLastMeshActivity') && 
                           $device->getLastMeshActivity() && 
                           $device->getLastMeshActivity() > (new \DateTime())->modify('-5 minutes');
                
                if ($isActive) {
                    $network['is_active'] = true;
                }
                
                // Get latest weight
                $latestData = $em->getRepository(\App\Entity\MicroData::class)
                    ->createQueryBuilder('m')
                    ->where('m.device = :device')
                    ->setParameter('device', $device)
                    ->orderBy('m.id', 'DESC')
                    ->setMaxResults(1)
                    ->getQuery()
                    ->getOneOrNullResult();
                
                $weight = $latestData ? $latestData->getWeight() : 0;
                $network['total_weight'] += $weight;
                
                $network['devices'][] = [
                    'device_id' => $device->getId(),
                    'mac_address' => $device->getMacAddress(),
                    'device_name' => $device->getSerialNumber() ?: ('Device #' . $device->getId()),
                    'role' => method_exists($device, 'getCurrentRole') ? ($device->getCurrentRole() ?: 'standalone') : 'standalone',
                    'signal_strength' => method_exists($device, 'getSignalStrength') ? $device->getSignalStrength() : null,
                    'last_seen' => method_exists($device, 'getLastMeshActivity') ? $device->getLastMeshActivity()?->format('Y-m-d H:i:s') : null,
                    'is_active' => $isActive,
                    'weight' => $weight,
                    'vehicle' => $device->getVehicle()?->__toString(),
                    'user_has_access' => in_array($device->getId(), array_map(fn($d) => $d->getId(), $userDevices))
                ];
            }
            
            $response[] = $network;
        }
        
        return new JsonResponse([
            'mesh_networks' => $response,
            'total_networks' => count($response),
            'timestamp' => (new \DateTime())->format('Y-m-d H:i:s')
        ]);
    }

    #[Route('/dashboard/truck-configurations', name: 'dashboard_truck_configurations')]
    public function truckConfigurations(EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        
        // Only try to access TruckConfiguration if it exists
        try {
            $configurations = $em->getRepository(TruckConfiguration::class)
                ->findBy(['owner' => $user], ['lastUsed' => 'DESC']);
        } catch (\Exception $e) {
            // Entity probably doesn't exist yet
            $configurations = [];
        }
        
        return $this->render('dashboard/truck_configurations.html.twig', [
            'configurations' => $configurations
        ]);
    }

    #[Route('/dashboard/truck-configuration/create', name: 'dashboard_create_truck_config')]
    public function createTruckConfiguration(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        
        // Get user's devices
        $userDevices = $this->getUserDevices($em, $user);
        
        if ($request->isMethod('POST')) {
            $data = json_decode($request->getContent(), true);
            
            try {
                $config = new TruckConfiguration();
                $config->setName($data['name']);
                $config->setOwner($user);
                $config->setLayout($data['layout']);
                
                $em->persist($config);
                
                // Create device roles
                foreach ($data['devices'] as $deviceData) {
                    $device = $em->getRepository(Device::class)->find($deviceData['id']);
                    if ($device && in_array($device, $userDevices)) {
                        $role = new DeviceRole();
                        $role->setDevice($device);
                        $role->setTruckConfiguration($config);
                        $role->setRole($deviceData['role']);
                        $role->setPosition($deviceData['position']);
                        $role->setSortOrder($deviceData['sortOrder']);
                        $role->setVisualPosition($deviceData['visualPosition']);
                        
                        $em->persist($role);
                    }
                }
                
                $em->flush();
                
                return new JsonResponse(['status' => 'success', 'id' => $config->getId()]);
            } catch (\Exception $e) {
                return new JsonResponse(['error' => 'Entity not found: ' . $e->getMessage()], 500);
            }
        }
        
        return $this->render('dashboard/create_truck_config.html.twig', [
            'devices' => $userDevices
        ]);
    }

    #[Route('/dashboard/truck-configuration/{id}/activate', name: 'dashboard_activate_truck_config')]
    public function activateTruckConfiguration(int $id, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        
        try {
            $config = $em->getRepository(TruckConfiguration::class)->find($id);
            if (!$config) {
                return new JsonResponse(['error' => 'Configuration not found'], 404);
            }
            
            if ($config->getOwner() !== $user) {
                return new JsonResponse(['error' => 'Access denied'], 403);
            }
            
            // Update last used
            $config->setLastUsed(new \DateTime());
            $em->flush();
            
            // Send configuration to ESP32 devices
            $this->sendConfigurationToDevices($config);
            
            return new JsonResponse(['status' => 'success']);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Entity not found: ' . $e->getMessage()], 500);
        }
    }

    #[Route('/dashboard/api/send-config-to-device', name: 'dashboard_send_config_to_device', methods: ['POST'])]
    public function sendConfigToDevice(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $deviceId = $data['device_id'];
        $configuration = $data['configuration'];
        
        $device = $em->getRepository(Device::class)->find($deviceId);
        if (!$device) {
            return new JsonResponse(['error' => 'Device not found'], 404);
        }
        
        // Here you would send the configuration to the ESP32
        // This could be via HTTP request if device is WiFi connected
        // Or queued for next BLE connection
        
        return new JsonResponse(['status' => 'success']);
    }

    private function getUserDevices(EntityManagerInterface $em, User $user): array
    {
        // Same logic as in your index method
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

        $purchasedDevices = $em->getRepository(Device::class)
            ->findBy(['soldTo' => $user]);

        foreach ($purchasedDevices as $device) {
            $userDevices[$device->getId()] = $device;
        }
        
        return array_values($userDevices);
    }

    private function sendConfigurationToDevices($config): void
    {
        // Implementation to send configuration to ESP32 devices
        // This would use your existing communication methods
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