<?php
//this file takes care of data transfer between any ESP32 devices and the website via a bridge device like a cellphone in bluetooth or thru the PWA. 
namespace App\Controller\Api;

use App\Entity\Device;
use App\Entity\DeviceAccess;
use App\Entity\MicroData;
use App\Entity\MicroDataChannel;
use App\Entity\User;
use App\Entity\UserConnectedVehicle;
use App\Entity\Vehicle;
use App\Service\DeviceChannelService;
use App\Service\VirtualSteerCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Psr\Log\LoggerInterface;

#[Route('/api/bridge', name: 'api_bridge_')]
class BridgeAPIController extends AbstractController
{
    #[Route('/register', name: 'register', methods: ['POST'])]
    public function register(
        Request $request,
        EntityManagerInterface $em,
        LoggerInterface $logger,
        DeviceChannelService $channelService,
        VirtualSteerCalculator $virtualSteerCalculator
    ): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $logger->error('Invalid JSON received at /api/bridge/register', ['error' => json_last_error_msg()]);
                return new JsonResponse(['error' => 'Invalid JSON'], 400);
            }
            
            $macAddress = $data['mac_address'] ?? null;
            $deviceType = $data['device_type'] ?? 'ESP32';
            $firmwareVersion = $data['firmware_version'] ?? 'unknown';
            
            if (!$macAddress) {
                return new JsonResponse(['error' => 'MAC address required'], 400);
            }
            
            // Normalize MAC address
            $macAddress = strtoupper($macAddress);
            
            $logger->info('ESP32 registration request via Bridge API', ['mac_address' => $macAddress]);
            
            // Find device (check both MAC variants for ESP32 BLE/WiFi difference)
            $device = $this->findDeviceByMac($em, $macAddress);
            
            if (!$device) {
                $logger->info('Creating new device during registration', ['mac_address' => $macAddress]);
                $device = new Device();
                $device->setMacAddress($macAddress);
                $device->setDeviceType($deviceType);
                $device->setFirmwareVersion($firmwareVersion);
                $device->setSerialNumber($data['serial_number'] ?? null);

                $em->persist($device);
                $em->flush();

                // Initialize default channels (Channel 1 and Channel 2)
                $channelService->initializeDefaultChannels($device);
            } else {
                if ($device->getFirmwareVersion() !== $firmwareVersion) {
                    $device->setFirmwareVersion($firmwareVersion);
                    $em->flush();
                }

                // Ensure existing devices have channels
                $channelService->initializeDefaultChannels($device);
            }
            
            $response = [
                'device_id' => $device->getId(),
                'mac_address' => $device->getMacAddress(),
                'status' => 'registered'
            ];

            // Return calibration for all channels
            $channelCalibrations = $channelService->getAllChannelCalibrations($device);
            if (!empty($channelCalibrations)) {
                $response['channel_calibrations'] = $channelCalibrations;
            }

            // DEPRECATED: Legacy single-channel calibration for backward compatibility
            $hasOldCalibration = $device->getRegressionIntercept() !== null ||
                                $device->getRegressionAirPressureCoeff() !== null;
            if ($hasOldCalibration) {
                $response['regression_coefficients'] = [
                    'intercept' => $device->getRegressionIntercept() ?? 0.0,
                    'air_pressure_coeff' => $device->getRegressionAirPressureCoeff() ?? 0.0,
                    'ambient_pressure_coeff' => $device->getRegressionAmbientPressureCoeff() ?? 0.0,
                    'air_temp_coeff' => $device->getRegressionAirTempCoeff() ?? 0.0,
                    'r_squared' => $device->getRegressionRsq() ?? 0.0,
                    'rmse' => $device->getRegressionRmse() ?? 0.0
                ];
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
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $logger->error('Invalid JSON received at /api/bridge/connect', ['error' => json_last_error_msg()]);
                return new JsonResponse(['error' => 'Invalid JSON'], 400);
            }
            
            $macAddress = $data['mac_address'] ?? null;
            $userId = $data['user_id'] ?? null;
            $deviceName = $data['device_name'] ?? null;
            
            if (!$macAddress || !$userId) {
                return new JsonResponse(['error' => 'MAC address and user ID required'], 400);
            }
            
            // Normalize MAC address
            $macAddress = strtoupper($macAddress);
            
            // Try to extract WiFi MAC from device name (e.g., "AirScale-9C:13:9E:BA:DC:90")
            $wifiMac = $this->extractMacFromDeviceName($deviceName);
            
            $logger->info('ESP32 connection request via Bridge API', [
                'mac_address' => $macAddress,
                'device_name' => $deviceName,
                'wifi_mac_extracted' => $wifiMac,
                'user_id' => $userId
            ]);
            
            // Find device - prefer WiFi MAC from device name, then try provided MAC
            $device = null;
            
            if ($wifiMac) {
                $device = $this->findDeviceByMac($em, $wifiMac);
                if ($device) {
                    $logger->info('Found device using WiFi MAC from device name', [
                        'wifi_mac' => $wifiMac,
                        'device_id' => $device->getId()
                    ]);
                }
            }
            
            if (!$device) {
                $device = $this->findDeviceByMac($em, $macAddress);
            }
            
            if (!$device) {
                $logger->info('Auto-registering new device during BLE connection', ['mac_address' => $wifiMac ?? $macAddress]);

                $device = new Device();
                $device->setMacAddress($wifiMac ?? $macAddress);
                $device->setDeviceType($data['device_type'] ?? 'ESP32');
                $device->setSerialNumber($deviceName);

                $em->persist($device);
                $em->flush();

                $logger->info('Device auto-registered successfully', ['device_id' => $device->getId()]);
            } else {
                // Update MAC address if it's NULL (device created before BLE connection)
                if (!$device->getMacAddress() && ($wifiMac || $macAddress)) {
                    $device->setMacAddress($wifiMac ?? $macAddress);
                    $em->flush();

                    $logger->info('Updated NULL MAC address for existing device', [
                        'device_id' => $device->getId(),
                        'mac_address' => $wifiMac ?? $macAddress
                    ]);
                }
            }
            
            $user = $em->getRepository(User::class)->find($userId);
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
                $access->setFirstSeenAt(new \DateTimeImmutable());
            }
            
            $access->setIsActive(true);
            $access->setLastConnectedAt(new \DateTime());
            
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

            $logger->info('ESP32 connected via Bridge API successfully', [
                'device_id' => $device->getId(),
                'user_id' => $userId
            ]);

            // Get user's vehicles for assignment modal
            $userVehicles = $em->getRepository(Vehicle::class)->findBy([
                'created_by' => $user
            ], ['updated_at' => 'DESC']);

            $vehicleList = [];
            foreach ($userVehicles as $v) {
                $vehicleList[] = [
                    'id' => $v->getId(),
                    'name' => $v->__toString()
                ];
            }

            return new JsonResponse([
                'status' => 'connected',
                'device_id' => $device->getId(),
                'mac_address' => $device->getMacAddress(),
                'vehicle_id' => $device->getVehicle()?->getId(),
                'needs_assignment' => $device->getVehicle() === null,
                'user_vehicles' => $vehicleList,
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
        LoggerInterface $logger,
        DeviceChannelService $channelService
    ): JsonResponse {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $logger->error('Invalid JSON received at /api/bridge/data', ['error' => json_last_error_msg()]);
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
            
            // Normalize MAC address
            $macAddress = strtoupper($macAddress);
            
            $logger->info('Data received via Bridge API', ['mac_address' => $macAddress]);
            
            $device = $this->findDeviceByMac($em, $macAddress);
            if (!$device) {
                $logger->info('Auto-provisioning device via Bridge API', ['mac_address' => $macAddress]);
                $device = new Device();
                $device->setMacAddress($macAddress);
                $device->setDeviceType($data['device_type'] ?? 'ESP32');
                $device->setSerialNumber($data['serial_number'] ?? null);
                $em->persist($device);
                $em->flush();

                // Initialize channels for new device
                $channelService->initializeDefaultChannels($device);
            } else {
                // Update MAC address if it's NULL
                if (!$device->getMacAddress()) {
                    $device->setMacAddress($macAddress);
                    $em->flush();

                    $logger->info('Updated NULL MAC address for existing device', [
                        'device_id' => $device->getId(),
                        'mac_address' => $macAddress
                    ]);
                }

                // Ensure device has channels
                $channelService->initializeDefaultChannels($device);
            }

            // Update firmware version and date if provided
            if (isset($data['firmware_version']) && $data['firmware_version'] !== null) {
                $device->setFirmwareVersion($data['firmware_version']);
            }
            if (isset($data['firmware_date']) && $data['firmware_date'] !== null) {
                try {
                    $firmwareDate = new \DateTime($data['firmware_date']);
                    $device->setFirmwareDate($firmwareDate);
                } catch (\Exception $e) {
                    $logger->warning('Invalid firmware_date format', ['firmware_date' => $data['firmware_date']]);
                }
            }
            if (isset($data['firmware_version']) || isset($data['firmware_date'])) {
                $em->flush(); // Save firmware info
            }

            // Handle batch data from phone (multiple readings) or single reading
            error_log('📦 BridgeAPI: Received data payload: ' . json_encode($data));

            // Check for new format with data_points array
            if (isset($data['data_points']) && is_array($data['data_points'])) {
                $dataPoints = $data['data_points'];
            } elseif (isset($data['batch_data']) && is_array($data['batch_data'])) {
                $dataPoints = $data['batch_data'];
            } else {
                // Legacy: single data point without wrapper
                $dataPoints = [$data];
            }

            error_log('📊 BridgeAPI: Processing ' . count($dataPoints) . ' data points');
            $processedCount = 0;
            $lastWeight = 0;
            
            foreach ($dataPoints as $point) {
                if (!is_array($point)) {
                    $logger->warning('Invalid data point in batch', ['point' => $point]);
                    continue;
                }

                error_log('🔍 BridgeAPI: Processing data point: ' . json_encode($point));

                $microData = new MicroData();
                $microData->setDevice($device);
                $microData->setMacAddress($macAddress);

                // Environmental data (shared across all channels)
                $microData->setAtmosphericPressure($point['atmospheric_pressure'] ?? null);
                $microData->setTemperature($point['temperature'] ?? null);
                $microData->setElevation($point['elevation'] ?? null);

                // ESP-NOW signal strength (from ESP32 mesh network)
                $microData->setEspnowRssi($point['espnow_rssi'] ?? null);

                // GPS from phone (optional enrichment - DEPRECATED in firmware)
                $microData->setGpsLat($point['gps_lat'] ?? null);
                $microData->setGpsLng($point['gps_lng'] ?? null);
                $microData->setGpsAccuracyM($point['gps_accuracy_m'] ?? null);

                $timestamp = $point['timestamp'] ?? 'now';
                try {
                    if (is_numeric($timestamp) && $timestamp < 1000000000) {
                        $microData->setTimestamp(new \DateTimeImmutable());
                    } else {
                        $microData->setTimestamp(new \DateTimeImmutable($timestamp));
                    }
                } catch (\Exception $e) {
                    $logger->warning('Invalid timestamp, using server time', ['timestamp' => $timestamp]);
                    $microData->setTimestamp(new \DateTimeImmutable());
                }

                // Handle channel data (new format) or legacy single-channel data
                if (isset($point['channels']) && is_array($point['channels'])) {
                    // New multi-channel format
                    error_log('🔵 BridgeAPI: Processing channel data for device ' . $device->getId() . ', channel_count=' . count($point['channels']));
                    $logger->info('Processing channel data', [
                        'device_id' => $device->getId(),
                        'channel_count' => count($point['channels']),
                        'channels' => $point['channels']
                    ]);

                    $totalWeight = 0;
                    $totalAirPressure = 0;
                    $channelCount = 0;

                    foreach ($point['channels'] as $channelData) {
                        $channelIndex = $channelData['channel_index'] ?? null;
                        if ($channelIndex === null) {
                            $logger->warning('Channel index is null, skipping');
                            continue;
                        }

                        $deviceChannel = $device->getChannel($channelIndex);
                        if (!$deviceChannel) {
                            error_log('⚠️ BridgeAPI: DeviceChannel not found for device ' . $device->getId() . ', channel_index=' . $channelIndex);
                            $logger->warning('Channel not found for device', [
                                'device_id' => $device->getId(),
                                'channel_index' => $channelIndex
                            ]);
                            continue;
                        }

                        error_log('✅ BridgeAPI: Creating MicroDataChannel - device=' . $device->getId() . ', ch=' . $channelIndex . ', device_channel_id=' . $deviceChannel->getId() . ', air_pressure=' . ($channelData['air_pressure'] ?? 'null'));
                        $logger->info('Creating MicroDataChannel', [
                            'device_id' => $device->getId(),
                            'channel_index' => $channelIndex,
                            'device_channel_id' => $deviceChannel->getId(),
                            'air_pressure' => $channelData['air_pressure'] ?? null,
                            'weight' => $channelData['weight'] ?? null
                        ]);

                        $microDataChannel = new MicroDataChannel();
                        $microDataChannel->setMicroData($microData);
                        $microDataChannel->setDeviceChannel($deviceChannel);
                        $microDataChannel->setAirPressure($channelData['air_pressure'] ?? null);

                        // Calculate weight if not provided but channel has regression coefficients
                        $channelWeight = $channelData['weight'] ?? null;
                        if ($channelWeight === null && isset($channelData['air_pressure'])) {
                            $intercept = $deviceChannel->getRegressionIntercept();
                            $airPressureCoeff = $deviceChannel->getRegressionAirPressureCoeff();

                            if ($intercept !== null && $airPressureCoeff !== null) {
                                $channelWeight = $intercept + ($channelData['air_pressure'] * $airPressureCoeff);
                                $channelWeight = max(0, $channelWeight);
                                error_log('⚖️ BridgeAPI: Calculated weight for device ' . $device->getId() . ' channel ' . $channelIndex . ': ' . round($channelWeight, 2) . ' lbs (from ' . $channelData['air_pressure'] . ' psi)');
                            }
                        }

                        $microDataChannel->setWeight($channelWeight);

                        $microData->addMicroDataChannel($microDataChannel);
                        error_log('📝 BridgeAPI: Added MicroDataChannel to MicroData, total channels=' . $microData->getMicroDataChannels()->count());

                        // Accumulate for aggregation
                        if (isset($channelData['air_pressure'])) {
                            $totalAirPressure += $channelData['air_pressure'];
                            $channelCount++;
                        }
                        if ($channelWeight !== null) {
                            $totalWeight += $channelWeight;
                        }
                        $lastWeight = $channelWeight ?? 0.0; // Track last weight for response
                    }

                    // Aggregate channel data into main MicroData fields
                    if ($channelCount > 0) {
                        $microData->setMainAirPressure($totalAirPressure / $channelCount);
                        error_log('📊 BridgeAPI: Set main_air_pressure=' . ($totalAirPressure / $channelCount) . ' (avg of ' . $channelCount . ' channels)');
                    }
                    $microData->setWeight($totalWeight);
                    error_log('📊 BridgeAPI: Set total weight=' . $totalWeight);
                } else {
                    // Legacy single-channel format (backward compatibility)
                    $microData->setMainAirPressure($point['main_air_pressure'] ?? null);
                    $lastWeight = $this->calculateWeight($device, $microData, $point['weight'] ?? null);
                    $microData->setWeight($lastWeight);
                }

                $em->persist($microData);
                error_log('💾 BridgeAPI: Persisted MicroData id=' . ($microData->getId() ?? 'new') . ' with ' . $microData->getMicroDataChannels()->count() . ' channels');
                $processedCount++;
            }

            error_log('🚀 BridgeAPI: Flushing ' . $processedCount . ' MicroData records to database');
            $em->flush();
            error_log('✅ BridgeAPI: Flush complete');
            
            $logger->info('Data processed via Bridge API successfully', [
                'device_id' => $device->getId(),
                'points_processed' => $processedCount,
                'last_weight' => $lastWeight
            ]);

            // Calculate virtual steer weight if device has it enabled
            $virtualSteerWeight = null;
            if ($device->hasVirtualSteer() && $totalWeight > 0) {
                $virtualSteerWeight = $virtualSteerCalculator->calculateSteerWeight($device, $totalWeight);
                if ($virtualSteerWeight !== null) {
                    error_log('🚗 BridgeAPI: Calculated virtual steer weight for device ' . $device->getId() . ': ' . round($virtualSteerWeight, 2) . ' lbs');
                }
            }

            $response = [
                'status' => $processedCount > 1 ? 'batch_received' : 'data_received',
                'points_processed' => $processedCount,
                'device_id' => $device->getId(),
                'calculated_weight' => $lastWeight,
                'virtual_steer_weight' => $virtualSteerWeight,
                'timestamp' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')
            ];
            
            $sendCoefficients = $data['request_coefficients'] ?? false;

            if ($sendCoefficients) {
                // Return calibration for all channels (new format)
                $channelCalibrations = $channelService->getAllChannelCalibrations($device);
                if (!empty($channelCalibrations)) {
                    $response['channel_calibrations'] = $channelCalibrations;
                }

                // DEPRECATED: Legacy single-channel calibration for backward compatibility
                $hasOldCalibration = $device->getRegressionIntercept() !== null ||
                                    $device->getRegressionAirPressureCoeff() !== null;
                if ($hasOldCalibration) {
                    $response['regression_coefficients'] = [
                        'intercept' => $device->getRegressionIntercept() ?? 0.0,
                        'air_pressure_coeff' => $device->getRegressionAirPressureCoeff() ?? 0.0,
                        'ambient_pressure_coeff' => $device->getRegressionAmbientPressureCoeff() ?? 0.0,
                        'air_temp_coeff' => $device->getRegressionAirTempCoeff() ?? 0.0
                    ];
                }

                // If this is a hub/master device, include coefficients for slave devices
                if ($device->isMeshMaster() && $device->getConnectedSlaves()) {
                    $slaveCoefficients = [];

                    foreach ($device->getConnectedSlaves() as $slaveMac) {
                        // Find slave device by MAC address
                        $slaveDevice = $em->getRepository(Device::class)->findOneBy(['macAddress' => strtoupper($slaveMac)]);

                        if ($slaveDevice) {
                            $slaveChannelCalibrations = $channelService->getAllChannelCalibrations($slaveDevice);
                            if (!empty($slaveChannelCalibrations)) {
                                $slaveCoefficients[$slaveMac] = [
                                    'device_id' => $slaveDevice->getId(),
                                    'channel_calibrations' => $slaveChannelCalibrations
                                ];
                                error_log('📡 BridgeAPI: Including coefficients for slave device ' . $slaveDevice->getId() . ' (MAC: ' . $slaveMac . ')');
                            }
                        }
                    }

                    if (!empty($slaveCoefficients)) {
                        $response['slave_coefficients'] = $slaveCoefficients;
                    }
                }
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
    
    /**
     * Find device by MAC address, checking both the exact MAC and ±1 variants
     * (ESP32 has different MACs for WiFi and Bluetooth, typically off by 1)
     */
    private function findDeviceByMac(EntityManagerInterface $em, string $macAddress): ?Device
    {
        $macAddress = strtoupper($macAddress);
        
        // Try exact match first
        $device = $em->getRepository(Device::class)->findOneBy(['macAddress' => $macAddress]);
        if ($device) {
            return $device;
        }
        
        // Try +1 variant (BLE MAC is usually WiFi MAC +1)
        $alternateMacPlus = $this->adjustMacAddress($macAddress, 1);
        if ($alternateMacPlus) {
            $device = $em->getRepository(Device::class)->findOneBy(['macAddress' => $alternateMacPlus]);
            if ($device) {
                return $device;
            }
        }
        
        // Try -1 variant
        $alternateMacMinus = $this->adjustMacAddress($macAddress, -1);
        if ($alternateMacMinus) {
            $device = $em->getRepository(Device::class)->findOneBy(['macAddress' => $alternateMacMinus]);
            if ($device) {
                return $device;
            }
        }
        
        return null;
    }
    
    /**
     * Adjust MAC address by incrementing/decrementing the last byte
     */
    private function adjustMacAddress(string $mac, int $adjustment): ?string
    {
        $parts = explode(':', $mac);
        if (count($parts) !== 6) {
            return null;
        }
        
        $lastByte = hexdec($parts[5]);
        $newLastByte = ($lastByte + $adjustment + 256) % 256;
        $parts[5] = strtoupper(sprintf('%02X', $newLastByte));
        
        return implode(':', $parts);
    }
    
    /**
     * Extract WiFi MAC from device name (e.g., "AirScale-9C:13:9E:BA:DC:90")
     */
    private function extractMacFromDeviceName(?string $deviceName): ?string
    {
        if (!$deviceName) {
            return null;
        }
        
        // Match pattern like "AirScale-XX:XX:XX:XX:XX:XX"
        if (preg_match('/AirScale-([0-9A-Fa-f:]{17})/i', $deviceName, $matches)) {
            return strtoupper($matches[1]);
        }
        
        return null;
    }
    
    private function calculateWeight(Device $device, MicroData $microData, ?float $providedWeight = null): float
    {
        if ($providedWeight !== null) {
            return $providedWeight;
        }
        
        $intercept = $device->getRegressionIntercept() ?? 0.0;
        $airPressureCoeff = $device->getRegressionAirPressureCoeff() ?? 0.0;
        $ambientPressureCoeff = $device->getRegressionAmbientPressureCoeff() ?? 0.0;
        $airTempCoeff = $device->getRegressionAirTempCoeff() ?? 0.0;
        
        if (!$intercept && !$airPressureCoeff && !$ambientPressureCoeff && !$airTempCoeff) {
            return 0.0;
        }
        
        $weight = $intercept + 
                  ($microData->getMainAirPressure() * $airPressureCoeff) +
                  ($microData->getAtmosphericPressure() * $ambientPressureCoeff) +
                  ($microData->getTemperature() * $airTempCoeff);
        
        return max(0, $weight);
    }

    // Mesh networking methods

    #[Route('/mesh/register', name: 'mesh_register', methods: ['POST'])]
    public function meshRegister(Request $request, EntityManagerInterface $em, LoggerInterface $logger): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse(['error' => 'Invalid JSON'], 400);
            }
            
            $macAddress = strtoupper($data['mac_address'] ?? '');
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
            
            $device = $this->findDeviceByMac($em, $macAddress);
            if (!$device) {
                $device = new Device();
                $device->setMacAddress($macAddress);
                $device->setDeviceType($data['device_type'] ?? 'ESP32');
                $device->setSerialNumber($data['serial_number'] ?? null);
                $em->persist($device);
            }
            
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
            
            $masterMac = strtoupper($data['master_mac'] ?? '');
            $aggregatedData = $data['aggregated_data'] ?? [];
            $meshTopology = $data['mesh_topology'] ?? [];
            
            if (!$masterMac) {
                return new JsonResponse(['error' => 'Master MAC required'], 400);
            }
            
            $logger->info('Mesh aggregated data received', [
                'master_mac' => $masterMac,
                'device_count' => count($aggregatedData)
            ]);
            
            $masterDevice = $this->findDeviceByMac($em, $masterMac);
            if (!$masterDevice) {
                return new JsonResponse(['error' => 'Master device not found'], 404);
            }
            
            $totalWeight = 0;
            $processedDevices = [];
            
            foreach ($aggregatedData as $deviceData) {
                $deviceMac = strtoupper($deviceData['mac_address'] ?? '');
                if (!$deviceMac) continue;
                
                $device = $this->findDeviceByMac($em, $deviceMac);
                if (!$device) {
                    $device = new Device();
                    $device->setMacAddress($deviceMac);
                    $device->setDeviceType('ESP32');
                    $device->setCurrentRole('slave');
                    $device->setMasterDeviceMac($masterMac);
                    $em->persist($device);
                }
                
                $device->setLastMeshActivity(new \DateTime());
                $device->setSignalStrength($deviceData['signal_strength'] ?? null);
                
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
                
                $weight = $this->calculateWeight($device, $microData, $deviceData['weight'] ?? null);
                $microData->setWeight($weight);
                $totalWeight += $weight;
                
                $em->persist($microData);
                $processedDevices[] = [
                    'mac_address' => $deviceMac,
                    'weight' => $weight,
                    'role' => $deviceData['role'] ?? 'slave'
                ];
            }
            
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
        $userMac = strtoupper($request->query->get('mac_address', ''));
        if (!$userMac) {
            return new JsonResponse(['error' => 'MAC address required'], 400);
        }
        
        $userDevice = $this->findDeviceByMac($em, $userMac);
        if (!$userDevice) {
            return new JsonResponse(['error' => 'Device not found'], 404);
        }
        
        $meshDevices = [];
        
        if ($userDevice->isMeshMaster()) {
            $meshDevices = $em->getRepository(Device::class)->findBy([
                'masterDeviceMac' => $userMac
            ]);
            $meshDevices[] = $userDevice;
        } elseif ($userDevice->isMeshSlave()) {
            $masterMac = $userDevice->getMasterDeviceMac();
            if ($masterMac) {
                $masterDevice = $this->findDeviceByMac($em, $masterMac);
                if ($masterDevice) {
                    $meshDevices[] = $masterDevice;
                    $slaves = $em->getRepository(Device::class)->findBy([
                        'masterDeviceMac' => $masterMac
                    ]);
                    $meshDevices = array_merge($meshDevices, $slaves);
                }
            }
        }
        
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
            
            $macAddress = strtoupper($data['mac_address'] ?? '');
            $newRole = $data['role'] ?? null;
            $masterMac = $data['master_mac'] ?? null;
            
            if (!$macAddress || !$newRole) {
                return new JsonResponse(['error' => 'MAC address and role required'], 400);
            }
            
            $device = $this->findDeviceByMac($em, $macAddress);
            if (!$device) {
                return new JsonResponse(['error' => 'Device not found'], 404);
            }
            
            $logger->info('Assigning mesh role', [
                'mac_address' => $macAddress,
                'old_role' => $device->getCurrentRole(),
                'new_role' => $newRole
            ]);
            
            $device->setCurrentRole($newRole);
            $device->setLastMeshActivity(new \DateTime());
            
            if ($newRole === 'slave' && $masterMac) {
                $device->setMasterDeviceMac($masterMac);
            } elseif ($newRole === 'master') {
                $device->setMasterDeviceMac(null);
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