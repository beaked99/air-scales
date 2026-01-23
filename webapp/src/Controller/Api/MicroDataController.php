<?php

//MicroDataController.php - ESP32 → WiFi → Server. ignores the phone. i have wifi, so therefore i dont need data from your phone to send up to the website. 

namespace App\Controller\Api;

use App\Entity\MicroData;
use App\Entity\Device;
use App\Repository\DeviceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Psr\Log\LoggerInterface;

class MicroDataController extends AbstractController
{
    #[Route('/api/microdata', name: 'api_microdata', methods: ['POST'])]
    public function post(
        Request $request,
        LoggerInterface $logger,
        EntityManagerInterface $em,
        DeviceRepository $deviceRepo
    ): JsonResponse {
        try {
            $logger->info('=== MicroDataController POST Request Started ===');
            
            $rawContent = $request->getContent();
            $logger->info('Raw request content', ['content' => $rawContent]);
            
            $data = json_decode($rawContent, true);
            if (!$data) {
                $jsonError = json_last_error_msg();
                $logger->error('Invalid JSON received at /api/microdata', ['json_error' => $jsonError, 'raw_content' => $rawContent]);
                return new JsonResponse(['error' => 'Invalid JSON: ' . $jsonError], 400);
            }

            $logger->info('Direct ESP32 data received', ['mac_address' => $data['mac_address'] ?? 'unknown', 'data' => $data]);

            // Required fields
            $requiredFields = [
                'mac_address', 'main_air_pressure', 'atmospheric_pressure',
                'temperature', 'elevation', 'gps_lat', 'gps_lng',
                'timestamp'
            ];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field])) {
                    $logger->error("Missing field: $field", $data);
                    return new JsonResponse(['error' => "Missing field: $field"], 400);
                }
            }

            $logger->info('All required fields present, proceeding with device lookup');

            // 🔐 Auto-provision the device if it's missing
            $device = $deviceRepo->findOneBy(['macAddress' => $data['mac_address']]);
            if (!$device) {
                $logger->info('Auto-provisioning new device', ['mac_address' => $data['mac_address']]);
                $device = new Device();
                $device->setMacAddress($data['mac_address']);
                $device->setDeviceType($data['device_type'] ?? 'ESP32');
                $device->setSerialNumber($data['serial_number'] ?? null);
                
                $em->persist($device);
                
                try {
                    $em->flush();
                    $logger->info('Device auto-provisioned successfully', ['device_id' => $device->getId()]);
                } catch (\Exception $e) {
                    $logger->error('Failed to auto-provision device', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                    throw $e;
                }
            } else {
                $logger->info('Device found', ['device_id' => $device->getId()]);
            }

            // 📝 Save the microdata
            $logger->info('Creating MicroData record');
            $micro = new MicroData();
            $micro->setDevice($device);
            $micro->setMacAddress($data['mac_address']);
            $micro->setMainAirPressure($data['main_air_pressure']);
            $micro->setAtmosphericPressure($data['atmospheric_pressure']);
            $micro->setTemperature($data['temperature']);
            $micro->setElevation($data['elevation']);
            $micro->setGpsLat($data['gps_lat']);
            $micro->setGpsLng($data['gps_lng']);
            
            // Handle timestamp - ESP32 sends millis(), convert to proper datetime
            $timestamp = $data['timestamp'];
            $logger->info('Processing timestamp', ['timestamp' => $timestamp, 'is_numeric' => is_numeric($timestamp)]);
            
            try {
                if (is_numeric($timestamp) && $timestamp < 1000000000) {
                    // This looks like millis() from ESP32, use server time instead
                    $micro->setTimestamp(new \DateTimeImmutable());
                    $logger->info('Using server time for timestamp (ESP32 millis detected)');
                } else {
                    $micro->setTimestamp(new \DateTimeImmutable($timestamp));
                    $logger->info('Using provided timestamp', ['parsed_timestamp' => $micro->getTimestamp()->format('Y-m-d H:i:s')]);
                }
            } catch (\Exception $e) {
                $logger->warning('Failed to parse timestamp, using server time', ['timestamp' => $timestamp, 'error' => $e->getMessage()]);
                $micro->setTimestamp(new \DateTimeImmutable());
            }

            // Calculate weight using regression if available
            $logger->info('Calculating weight');
            $weight = $this->calculateWeight($device, $micro, $data['weight'] ?? null);
            $micro->setWeight($weight);
            $logger->info('Weight calculated', ['weight' => $weight]);

            // Persist the MicroData
            $logger->info('Persisting MicroData record');
            $em->persist($micro);
            
            // Note: Device and Vehicle entities don't have lastSeen properties
            // Using TimestampableTrait updatedAt instead would be handled automatically
            $logger->info('Skipping lastSeen updates - using TimestampableTrait updatedAt instead');
            
            // Flush all changes
            $logger->info('Flushing changes to database');
            try {
                $em->flush();
                $logger->info('Database flush successful');
            } catch (\Exception $e) {
                $logger->error('Database flush failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                throw $e;
            }

            $logger->info('Direct ESP32 data saved successfully', [
                'device_id' => $device->getId(),
                'weight' => $weight,
                'mac_address' => $data['mac_address'],
                'micro_data_id' => $micro->getId()
            ]);

            // Prepare response
            $response = [
                'success' => true,
                'calculated_weight' => $weight,
                'device_id' => $device->getId(),
                'micro_data_id' => $micro->getId(),
                'timestamp' => $micro->getTimestamp()->format('Y-m-d H:i:s')
            ];

            // Only include regression coefficients if they exist (device has been calibrated)
            $hasCalibration = $device->getRegressionIntercept() !== null ||
                             $device->getRegressionAirPressureCoeff() !== null ||
                             $device->getRegressionAmbientPressureCoeff() !== null ||
                             $device->getRegressionAirTempCoeff() !== null;

            if ($hasCalibration) {
                $response['regression_coefficients'] = [
                    'intercept' => $device->getRegressionIntercept() ?? 0.0,
                    'air_pressure_coeff' => $device->getRegressionAirPressureCoeff() ?? 0.0,
                    'ambient_pressure_coeff' => $device->getRegressionAmbientPressureCoeff() ?? 0.0,
                    'air_temp_coeff' => $device->getRegressionAirTempCoeff() ?? 0.0
                ];
                $logger->info('Sending regression coefficients to ESP32', [
                    'device_id' => $device->getId(),
                    'coefficients' => $response['regression_coefficients']
                ]);
            } else {
                $logger->info('No calibration data available for device', ['device_id' => $device->getId()]);
            }

            $logger->info('=== MicroDataController POST Request Completed Successfully ===', ['response' => $response]);
            return new JsonResponse($response);
            
        } catch (\Exception $e) {
            $logger->error('=== MicroDataController POST Request Failed ===', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return new JsonResponse([
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
                'debug' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ], 500);
        }
    }

    #[Route('/api/microdata/{mac}/latest', name: 'api_microdata_latest', methods: ['GET'])]
    public function latestAmbient(string $mac, EntityManagerInterface $em): JsonResponse
    {
        // Order by ID instead of timestamp to avoid corrupted timestamp issues
        $latest = $em->getRepository(MicroData::class)
            ->createQueryBuilder('m')
            ->where('m.macAddress = :mac')
            ->setParameter('mac', $mac)
            ->orderBy('m.id', 'DESC')  // ← Changed from timestamp to ID
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$latest) {
            return new JsonResponse(null, 404);
        }

        return new JsonResponse([
            'ambient' => $latest->getAtmosphericPressure(),
            'temperature' => $latest->getTemperature(),
            'weight' => $latest->getWeight(),
            'timestamp' => $latest->getTimestamp()->format('Y-m-d H:i:s'),
            'micro_data_id' => $latest->getId() // Add this for debugging
        ]);
    }

    private function calculateWeight(Device $device, MicroData $microData, ?float $providedWeight = null): float
    {
        // If weight is provided in the data, use it (backwards compatibility)
        if ($providedWeight !== null) {
            return $providedWeight;
        }

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