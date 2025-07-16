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
            }
            
            $access->setIsActive(true);
            $access->setLastAccessedAt(new \DateTimeImmutable());
            
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
}