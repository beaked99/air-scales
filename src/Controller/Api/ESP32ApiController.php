<?php

namespace App\Controller\Api;

use App\Entity\Device;
use App\Entity\DeviceAccess;
use App\Entity\MicroData;
use App\Entity\User; // ← Added missing import
use App\Entity\UserConnectedVehicle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Psr\Log\LoggerInterface; // ← Added logger support

#[Route('/api/esp32', name: 'api_esp32_')]
class ESP32ApiController extends AbstractController
{
    #[Route('/register', name: 'register', methods: ['POST'])]
    public function register(Request $request, EntityManagerInterface $em, LoggerInterface $logger): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            // Add JSON parsing error check
            if (json_last_error() !== JSON_ERROR_NONE) {
                $logger->error('Invalid JSON received at /api/esp32/register', ['error' => json_last_error_msg()]);
                return new JsonResponse(['error' => 'Invalid JSON'], 400);
            }
            
            $macAddress = $data['mac_address'] ?? null;
            $deviceType = $data['device_type'] ?? 'ESP32';
            $firmwareVersion = $data['firmware_version'] ?? 'unknown';
            
            if (!$macAddress) {
                return new JsonResponse(['error' => 'MAC address required'], 400);
            }
            
            $logger->info('ESP32 registration request', ['mac_address' => $macAddress]);
            
            // Find or create device
            $device = $em->getRepository(Device::class)->findOneBy(['macAddress' => $macAddress]);
            
            if (!$device) {
                $logger->info('Creating new device during registration', ['mac_address' => $macAddress]);
                $device = new Device();
                $device->setMacAddress($macAddress);
                $device->setDeviceType($deviceType);
                $device->setFirmwareVersion($firmwareVersion);
                $device->setSerialNumber($data['serial_number'] ?? null);
                
                $em->persist($device);
                $em->flush();
            } else {
                // Update firmware version if newer
                if ($device->getFirmwareVersion() !== $firmwareVersion) {
                    $device->setFirmwareVersion($firmwareVersion);
                    $em->flush();
                }
            }
            
            // Prepare response with coefficients (only if they exist)
            $response = [
                'device_id' => $device->getId(),
                'mac_address' => $device->getMacAddress(),
                'status' => 'registered'
            ];
            
            // Only include regression coefficients if device has been calibrated
            $hasCalibration = $device->getRegressionIntercept() !== null ||
                             $device->getRegressionAirPressureCoeff() !== null ||
                             $device->getRegressionAmbientPressureCoeff() !== null ||
                             $device->getRegressionAirTempCoeff() !== null;

            if ($hasCalibration) {
                $response['regression_coefficients'] = [
                    'intercept' => $device->getRegressionIntercept() ?? 0.0,
                    'air_pressure_coeff' => $device->getRegressionAirPressureCoeff() ?? 0.0,
                    'ambient_pressure_coeff' => $device->getRegressionAmbientPressureCoeff() ?? 0.0,
                    'air_temp_coeff' => $device->getRegressionAirTempCoeff() ?? 0.0,
                    'r_squared' => $device->getRegressionRsq() ?? 0.0,
                    'rmse' => $device->getRegressionRmse() ?? 0.0
                ];
                $logger->info('Sending regression coefficients during registration', [
                    'device_id' => $device->getId(),
                    'coefficients' => $response['regression_coefficients']
                ]);
            } else {
                $logger->info('No calibration data available during registration', ['device_id' => $device->getId()]);
            }
            
            return new JsonResponse($response);
            
        } catch (\Exception $e) {
            $logger->error('ESP32 registration failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return new JsonResponse([
                'error' => 'Registration failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    #[Route('/connect', name: 'connect', methods: ['POST'])]
    public function connect(Request $request, EntityManagerInterface $em, LoggerInterface $logger): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            // Add JSON parsing error check
            if (json_last_error() !== JSON_ERROR_NONE) {
                $logger->error('Invalid JSON received at /api/esp32/connect', ['error' => json_last_error_msg()]);
                return new JsonResponse(['error' => 'Invalid JSON'], 400);
            }
            
            $macAddress = $data['mac_address'] ?? null;
            $userId = $data['user_id'] ?? null; // From PWA authentication
            
            if (!$macAddress || !$userId) {
                return new JsonResponse(['error' => 'MAC address and user ID required'], 400);
            }
            
            $logger->info('ESP32 connection request via PWA', [
                'mac_address' => $macAddress,
                'user_id' => $userId
            ]);
            
            $device = $em->getRepository(Device::class)->findOneBy(['macAddress' => $macAddress]);
            if (!$device) {
                return new JsonResponse(['error' => 'Device not found'], 404);
            }
            
            $user = $em->getRepository(User::class)->find($userId); // ← Now properly imported
            if (!$user) {
                return new JsonResponse(['error' => 'User not found'], 404);
            }
            
            // Create or update device access
            $access = $em->getRepository(DeviceAccess::class)->findOneBy([
                'device' => $device,
                'user' => $user
            ]);
            
            if (!$access) {
                $access = new DeviceAccess();
                $access->setDevice($device);
                $access->setUser($user);
                $access->setFirstSeenAt(new \DateTimeImmutable()); // Add this for new connections
            }

            
            $access->setIsActive(true);
            $access->setLastConnectedAt(new \DateTimeImmutable());
            
            $em->persist($access);
            
            // Create UserConnectedVehicle if device has a vehicle
            if ($device->getVehicle()) {
                $connection = $em->getRepository(UserConnectedVehicle::class)->findOneBy([
                    'user' => $user,
                    'vehicle' => $device->getVehicle()
                ]);
                
                if (!$connection) {
                    $connection = new UserConnectedVehicle();
                    $connection->setUser($user);
                    $connection->setVehicle($device->getVehicle());
                }
                
                $connection->setIsConnected(true);
                $em->persist($connection);
            }
            
            $em->flush();
            
            $logger->info('ESP32 connected via PWA successfully', [
                'device_id' => $device->getId(),
                'user_id' => $userId
            ]);
            
            return new JsonResponse([
                'status' => 'connected',
                'device_id' => $device->getId(),
                'vehicle_info' => $device->getVehicle() ? [
                    'id' => $device->getVehicle()->getId(),
                    'name' => $device->getVehicle()->__toString(),
                    'owner' => $device->getVehicle()->getCreatedBy() ? $device->getVehicle()->getCreatedBy()->getFullName() : 'Unknown'
                ] : null
            ]);
            
        } catch (\Exception $e) {
            $logger->error('ESP32 connection failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return new JsonResponse([
                'error' => 'Connection failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    #[Route('/data', name: 'data', methods: ['POST'])]
    public function receiveDataViaPhone(
        Request $request, 
        EntityManagerInterface $em,
        LoggerInterface $logger
    ): JsonResponse {
        try {
            $data = json_decode($request->getContent(), true);
            
            // Add error checking for JSON parsing
            if (json_last_error() !== JSON_ERROR_NONE) {
                $logger->error('Invalid JSON received at /api/esp32/data', ['error' => json_last_error_msg()]);
                return new JsonResponse(['error' => 'Invalid JSON'], 400);
            }
            // Check if this is mesh aggregated data or single device data
            if (isset($data['mesh_data']) && $data['mesh_data'] === true) {
                return $this->meshData($request, $em, $logger);
            }

            $macAddress = $data['mac_address'] ?? null;
            if (!$macAddress) {
                return new JsonResponse(['error' => 'MAC address required'], 400);
            }
            
            $logger->info('Data received via PWA/phone', ['mac_address' => $macAddress]);
            
            $device = $em->getRepository(Device::class)->findOneBy(['macAddress' => $macAddress]);
            if (!$device) {
                // Auto-provision device (same as MicroDataController)
                $logger->info('Auto-provisioning device via PWA', ['mac_address' => $macAddress]);
                $device = new Device();
                $device->setMacAddress($macAddress);
                $device->setDeviceType($data['device_type'] ?? 'ESP32');
                $device->setSerialNumber($data['serial_number'] ?? null);
                $em->persist($device);
                $em->flush();
            }
            
            // Handle batch data from phone (multiple readings) or single reading
            $dataPoints = isset($data['batch_data']) && is_array($data['batch_data']) ? $data['batch_data'] : [$data];
            $processedCount = 0;
            $lastWeight = 0;
            
            foreach ($dataPoints as $point) {
                // Validate required fields for each point
                if (!is_array($point)) {
                    $logger->warning('Invalid data point in batch', ['point' => $point]);
                    continue;
                }
                
                // Create MicroData record
                $microData = new MicroData();
                $microData->setDevice($device);
                $microData->setMacAddress($macAddress);
                $microData->setMainAirPressure($point['main_air_pressure'] ?? 0.0);
                $microData->setAtmosphericPressure($point['atmospheric_pressure'] ?? 0.0);
                $microData->setTemperature($point['temperature'] ?? 0.0);
                $microData->setElevation($point['elevation'] ?? 0.0);
                $microData->setGpsLat($point['gps_lat'] ?? 0.0);
                $microData->setGpsLng($point['gps_lng'] ?? 0.0);
                
                // Handle timestamp from phone or ESP32
                $timestamp = $point['timestamp'] ?? 'now';
                try {
                    if (is_numeric($timestamp) && $timestamp < 1000000000) {
                        // This looks like millis() from ESP32, use server time instead
                        $microData->setTimestamp(new \DateTimeImmutable());
                    } else {
                        $microData->setTimestamp(new \DateTimeImmutable($timestamp));
                    }
                } catch (\Exception $e) {
                    $logger->warning('Invalid timestamp, using server time', ['timestamp' => $timestamp, 'error' => $e->getMessage()]);
                    $microData->setTimestamp(new \DateTimeImmutable());
                }
                
                // Calculate weight using regression coefficients
                $lastWeight = $this->calculateWeight($device, $microData);
                $microData->setWeight($lastWeight);
                
                $em->persist($microData);
                $processedCount++;
            }
            
            // REMOVED: setLastSeen calls that were causing errors
            // Note: Device and Vehicle entities don't have lastSeen properties
            // Using TimestampableTrait updatedAt instead would be handled automatically
            $logger->info('Skipping lastSeen updates - using TimestampableTrait updatedAt instead');
            
            $em->flush();
            
            $logger->info('Data processed via PWA successfully', [
                'device_id' => $device->getId(),
                'points_processed' => $processedCount,
                'last_weight' => $lastWeight
            ]);
            
            // Return response optimized for mobile data usage
            $response = [
                'status' => $processedCount > 1 ? 'batch_received' : 'data_received',
                'points_processed' => $processedCount,
                'device_id' => $device->getId(),
                'calculated_weight' => $lastWeight,
                'timestamp' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')
            ];
            
            // Only send coefficients if they exist and if requested (save mobile data)
            $sendCoefficients = $data['request_coefficients'] ?? false;
            $hasCalibration = $device->getRegressionIntercept() !== null ||
                             $device->getRegressionAirPressureCoeff() !== null ||
                             $device->getRegressionAmbientPressureCoeff() !== null ||
                             $device->getRegressionAirTempCoeff() !== null;
            
            if ($sendCoefficients && $hasCalibration) {
                $response['regression_coefficients'] = [
                    'intercept' => $device->getRegressionIntercept() ?? 0.0,
                    'air_pressure_coeff' => $device->getRegressionAirPressureCoeff() ?? 0.0,
                    'ambient_pressure_coeff' => $device->getRegressionAmbientPressureCoeff() ?? 0.0,
                    'air_temp_coeff' => $device->getRegressionAirTempCoeff() ?? 0.0
                ];
                $logger->info('Sending regression coefficients via PWA', [
                    'device_id' => $device->getId()
                ]);
            }
            
            return new JsonResponse($response);
            
        } catch (\Exception $e) {
            $logger->error('ESP32 data reception failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return new JsonResponse([
                'error' => 'Data reception failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    #[Route('/calibration/{deviceId}', name: 'get_calibration', methods: ['GET'])]
    public function getCalibration(int $deviceId, EntityManagerInterface $em): JsonResponse
    {
        $device = $em->getRepository(Device::class)->find($deviceId);
        if (!$device) {
            return new JsonResponse(['error' => 'Device not found'], 404);
        }
        
        $response = [
            'device_id' => $device->getId()
        ];
        
        // Only include regression coefficients if they exist
        $hasCalibration = $device->getRegressionIntercept() !== null ||
                         $device->getRegressionAirPressureCoeff() !== null ||
                         $device->getRegressionAmbientPressureCoeff() !== null ||
                         $device->getRegressionAirTempCoeff() !== null;

        if ($hasCalibration) {
            $response['regression_coefficients'] = [
                'intercept' => $device->getRegressionIntercept() ?? 0.0,
                'air_pressure_coeff' => $device->getRegressionAirPressureCoeff() ?? 0.0,
                'ambient_pressure_coeff' => $device->getRegressionAmbientPressureCoeff() ?? 0.0,
                'air_temp_coeff' => $device->getRegressionAirTempCoeff() ?? 0.0,
                'r_squared' => $device->getRegressionRsq() ?? 0.0,
                'rmse' => $device->getRegressionRmse() ?? 0.0
            ];
        }
        
        $response['last_calibration'] = $device->getCalibrations()->last() ? [
            'date' => $device->getCalibrations()->last()->getCreatedAt()->format('Y-m-d H:i:s'),
            'weight' => $device->getCalibrations()->last()->getScaleWeight()
        ] : null;
        
        return new JsonResponse($response);
    }
    
    #[Route('/status', name: 'status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'online',
            'server_time' => (new \DateTime())->format('Y-m-d H:i:s'),
            'version' => '1.0.0'
        ]);
    }
    
    private function calculateWeight(Device $device, MicroData $microData): float
    {
        $intercept = $device->getRegressionIntercept() ?? 0.0;
        $airPressureCoeff = $device->getRegressionAirPressureCoeff() ?? 0.0;
        $ambientPressureCoeff = $device->getRegressionAmbientPressureCoeff() ?? 0.0;
        $airTempCoeff = $device->getRegressionAirTempCoeff() ?? 0.0;
        
        // If no calibration data, return 0
        if (!$intercept && !$airPressureCoeff && !$ambientPressureCoeff && !$airTempCoeff) {
            return 0.0;
        }
        
        $weight = $intercept + 
                  ($microData->getMainAirPressure() * $airPressureCoeff) +
                  ($microData->getAtmosphericPressure() * $ambientPressureCoeff) +
                  ($microData->getTemperature() * $airTempCoeff);
        
        return max(0, $weight); // Don't allow negative weights
    }

    // Add these methods to your existing ESP32ApiController

    #[Route('/mesh/register', name: 'mesh_register', methods: ['POST'])]
    public function meshRegister(Request $request, EntityManagerInterface $em, LoggerInterface $logger): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse(['error' => 'Invalid JSON'], 400);
            }
            
            $macAddress = $data['mac_address'] ?? null;
            $role = $data['role'] ?? 'discovery';
            $masterMac = $data['master_mac'] ?? null;
            $connectedSlaves = $data['connected_slaves'] ?? [];
            $signalStrength = $data['signal_strength'] ?? null;
            
            if (!$macAddress) {
                return new JsonResponse(['error' => 'MAC address required'], 400);
            }
            
            $logger->info('Mesh registration request', [
                'mac_address' => $macAddress,
                'role' => $role,
                'master_mac' => $masterMac
            ]);
            
            $device = $em->getRepository(Device::class)->findOneBy(['macAddress' => $macAddress]);
            if (!$device) {
                // Auto-provision
                $device = new Device();
                $device->setMacAddress($macAddress);
                $device->setDeviceType($data['device_type'] ?? 'ESP32');
                $device->setSerialNumber($data['serial_number'] ?? null);
                $em->persist($device);
            }
            
            // Update mesh information
            $device->setCurrentRole($role);
            $device->setLastMeshActivity(new \DateTime());
            $device->setSignalStrength($signalStrength);
            $device->setMasterDeviceMac($masterMac);
            $device->setConnectedSlaves($connectedSlaves);
            
            $em->flush();
            
            return new JsonResponse([
                'status' => 'registered',
                'device_id' => $device->getId(),
                'role' => $role
            ]);
            
        } catch (\Exception $e) {
            $logger->error('Mesh registration failed', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => 'Registration failed'], 500);
        }
    }

    #[Route('/mesh/data', name: 'mesh_data', methods: ['POST'])]
    public function meshData(Request $request, EntityManagerInterface $em, LoggerInterface $logger): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse(['error' => 'Invalid JSON'], 400);
            }
            
            $masterMac = $data['master_mac'] ?? null;
            $aggregatedData = $data['aggregated_data'] ?? [];
            $meshTopology = $data['mesh_topology'] ?? [];
            
            if (!$masterMac) {
                return new JsonResponse(['error' => 'Master MAC required'], 400);
            }
            
            $logger->info('Mesh aggregated data received', [
                'master_mac' => $masterMac,
                'device_count' => count($aggregatedData)
            ]);
            
            $masterDevice = $em->getRepository(Device::class)->findOneBy(['macAddress' => $masterMac]);
            if (!$masterDevice) {
                return new JsonResponse(['error' => 'Master device not found'], 404);
            }
            
            // Process aggregated data from all devices in the mesh
            $totalWeight = 0;
            $processedDevices = [];
            
            foreach ($aggregatedData as $deviceData) {
                $deviceMac = $deviceData['mac_address'] ?? null;
                if (!$deviceMac) continue;
                
                $device = $em->getRepository(Device::class)->findOneBy(['macAddress' => $deviceMac]);
                if (!$device) {
                    // Auto-provision slave device
                    $device = new Device();
                    $device->setMacAddress($deviceMac);
                    $device->setDeviceType('ESP32');
                    $device->setCurrentRole('slave');
                    $device->setMasterDeviceMac($masterMac);
                    $em->persist($device);
                }
                
                // Update mesh activity
                $device->setLastMeshActivity(new \DateTime());
                $device->setSignalStrength($deviceData['signal_strength'] ?? null);
                
                // Create MicroData record
                $microData = new MicroData();
                $microData->setDevice($device);
                $microData->setMacAddress($deviceMac);
                $microData->setMainAirPressure($deviceData['main_air_pressure'] ?? 0.0);
                $microData->setAtmosphericPressure($deviceData['atmospheric_pressure'] ?? 0.0);
                $microData->setTemperature($deviceData['temperature'] ?? 0.0);
                $microData->setElevation($deviceData['elevation'] ?? 0.0);
                $microData->setGpsLat($deviceData['gps_lat'] ?? 0.0);
                $microData->setGpsLng($deviceData['gps_lng'] ?? 0.0);
                $microData->setTimestamp(new \DateTimeImmutable());
                
                // Calculate weight
                $weight = $this->calculateWeight($device, $microData);
                $microData->setWeight($weight);
                $totalWeight += $weight;
                
                $em->persist($microData);
                $processedDevices[] = [
                    'mac_address' => $deviceMac,
                    'weight' => $weight,
                    'role' => $deviceData['role'] ?? 'slave'
                ];
            }
            
            // Update master device with mesh topology
            $masterDevice->setConnectedSlaves(array_column($processedDevices, 'mac_address'));
            $masterDevice->setMeshConfiguration([
                'topology' => $meshTopology,
                'total_weight' => $totalWeight,
                'device_count' => count($processedDevices),
                'last_update' => (new \DateTime())->format('Y-m-d H:i:s')
            ]);
            
            $em->flush();
            
            $logger->info('Mesh data processed successfully', [
                'master_device_id' => $masterDevice->getId(),
                'total_weight' => $totalWeight,
                'devices_processed' => count($processedDevices)
            ]);
            
            return new JsonResponse([
                'status' => 'success',
                'total_weight' => $totalWeight,
                'devices_processed' => count($processedDevices),
                'master_device_id' => $masterDevice->getId()
            ]);
            
        } catch (\Exception $e) {
            $logger->error('Mesh data processing failed', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => 'Processing failed'], 500);
        }
    }

    #[Route('/mesh/topology', name: 'mesh_topology', methods: ['GET'])]
    public function getMeshTopology(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $userMac = $request->query->get('mac_address');
        if (!$userMac) {
            return new JsonResponse(['error' => 'MAC address required'], 400);
        }
        
        // Find the user's device
        $userDevice = $em->getRepository(Device::class)->findOneBy(['macAddress' => $userMac]);
        if (!$userDevice) {
            return new JsonResponse(['error' => 'Device not found'], 404);
        }
        
        // Find all devices in the same mesh network
        $meshDevices = [];
        
        if ($userDevice->isMeshMaster()) {
            // User is master - get all slaves
            $meshDevices = $em->getRepository(Device::class)->findBy([
                'masterDeviceMac' => $userMac
            ]);
            $meshDevices[] = $userDevice; // Include master
        } elseif ($userDevice->isMeshSlave()) {
            // User is slave - get master and other slaves
            $masterMac = $userDevice->getMasterDeviceMac();
            if ($masterMac) {
                $masterDevice = $em->getRepository(Device::class)->findOneBy(['macAddress' => $masterMac]);
                if ($masterDevice) {
                    $meshDevices[] = $masterDevice;
                    $slaves = $em->getRepository(Device::class)->findBy([
                        'masterDeviceMac' => $masterMac
                    ]);
                    $meshDevices = array_merge($meshDevices, $slaves);
                }
            }
        }
        
        // Format response
        $topology = [];
        foreach ($meshDevices as $device) {
            $topology[] = [
                'mac_address' => $device->getMacAddress(),
                'device_name' => $device->getSerialNumber() ?: ('Device #' . $device->getId()),
                'role' => $device->getCurrentRole(),
                'signal_strength' => $device->getSignalStrength(),
                'last_seen' => $device->getLastMeshActivity()?->format('Y-m-d H:i:s'),
                'is_active' => $device->getLastMeshActivity() && 
                            $device->getLastMeshActivity() > (new \DateTime())->modify('-5 minutes'),
                'vehicle' => $device->getVehicle()?->__toString(),
                'connected_slaves' => $device->getConnectedSlaves() ?: []
            ];
        }
        
        return new JsonResponse([
            'user_device' => [
                'mac_address' => $userDevice->getMacAddress(),
                'role' => $userDevice->getCurrentRole()
            ],
            'mesh_topology' => $topology,
            'device_count' => count($topology)
        ]);
    }

    #[Route('/mesh/assign-role', name: 'mesh_assign_role', methods: ['POST'])]
    public function assignMeshRole(Request $request, EntityManagerInterface $em, LoggerInterface $logger): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            $macAddress = $data['mac_address'] ?? null;
            $newRole = $data['role'] ?? null;
            $masterMac = $data['master_mac'] ?? null;
            
            if (!$macAddress || !$newRole) {
                return new JsonResponse(['error' => 'MAC address and role required'], 400);
            }
            
            $device = $em->getRepository(Device::class)->findOneBy(['macAddress' => $macAddress]);
            if (!$device) {
                return new JsonResponse(['error' => 'Device not found'], 404);
            }
            
            $logger->info('Assigning mesh role', [
                'mac_address' => $macAddress,
                'old_role' => $device->getCurrentRole(),
                'new_role' => $newRole
            ]);
            
            // Update device role
            $device->setCurrentRole($newRole);
            $device->setLastMeshActivity(new \DateTime());
            
            if ($newRole === 'slave' && $masterMac) {
                $device->setMasterDeviceMac($masterMac);
            } elseif ($newRole === 'master') {
                $device->setMasterDeviceMac(null);
                // Clear any existing master's slave list and update it
                $oldSlaves = $em->getRepository(Device::class)->findBy([
                    'masterDeviceMac' => $macAddress
                ]);
                $slaveList = array_map(fn($d) => $d->getMacAddress(), $oldSlaves);
                $device->setConnectedSlaves($slaveList);
            }
            
            $em->flush();
            
            return new JsonResponse([
                'status' => 'success',
                'device_id' => $device->getId(),
                'new_role' => $newRole
            ]);
            
        } catch (\Exception $e) {
            $logger->error('Role assignment failed', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => 'Assignment failed'], 500);
        }
    }
}