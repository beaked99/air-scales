<?php

namespace App\Controller\Api;

use App\Entity\Device;
use App\Entity\DeviceAccess;
use App\Entity\MicroData;
use App\Entity\UserConnectedVehicle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/esp32', name: 'api_esp32_')]
class ESP32ApiController extends AbstractController
{
    #[Route('/register', name: 'register', methods: ['POST'])]
    public function register(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        $macAddress = $data['mac_address'] ?? null;
        $deviceType = $data['device_type'] ?? 'ESP32';
        $firmwareVersion = $data['firmware_version'] ?? 'unknown';
        
        if (!$macAddress) {
            return new JsonResponse(['error' => 'MAC address required'], 400);
        }
        
        // Find or create device
        $device = $em->getRepository(Device::class)->findOneBy(['macAddress' => $macAddress]);
        
        if (!$device) {
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
        
        return new JsonResponse([
            'device_id' => $device->getId(),
            'mac_address' => $device->getMacAddress(),
            'status' => 'registered',
            'regression_coefficients' => [
                'intercept' => $device->getRegressionIntercept(),
                'air_pressure_coeff' => $device->getRegressionAirPressureCoeff(),
                'ambient_pressure_coeff' => $device->getRegressionAmbientPressureCoeff(),
                'air_temp_coeff' => $device->getRegressionAirTempCoeff(),
                'r_squared' => $device->getRegressionRsq(),
                'rmse' => $device->getRegressionRmse()
            ]
        ]);
    }
    
    #[Route('/connect', name: 'connect', methods: ['POST'])]
    public function connect(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        $macAddress = $data['mac_address'] ?? null;
        $userId = $data['user_id'] ?? null; // From PWA authentication
        
        if (!$macAddress || !$userId) {
            return new JsonResponse(['error' => 'MAC address and user ID required'], 400);
        }
        
        $device = $em->getRepository(Device::class)->findOneBy(['macAddress' => $macAddress]);
        if (!$device) {
            return new JsonResponse(['error' => 'Device not found'], 404);
        }
        
        $user = $em->getRepository(\App\Entity\User::class)->find($userId);
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
        
        return new JsonResponse([
            'status' => 'connected',
            'device_id' => $device->getId(),
            'vehicle_info' => $device->getVehicle() ? [
                'id' => $device->getVehicle()->getId(),
                'name' => $device->getVehicle()->__toString(),
                'owner' => $device->getVehicle()->getCreatedBy()->getFullName()
            ] : null
        ]);
    }
    
    #[Route('/data', name: 'data', methods: ['POST'])]
    public function receiveData(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        $macAddress = $data['mac_address'] ?? null;
        if (!$macAddress) {
            return new JsonResponse(['error' => 'MAC address required'], 400);
        }
        
        $device = $em->getRepository(Device::class)->findOneBy(['macAddress' => $macAddress]);
        if (!$device) {
            return new JsonResponse(['error' => 'Device not found'], 404);
        }
        
        // Create MicroData record
        $microData = new MicroData();
        $microData->setDevice($device);
        $microData->setMacAddress($macAddress);
        $microData->setMainAirPressure($data['main_air_pressure'] ?? 0.0);
        $microData->setAtmosphericPressure($data['atmospheric_pressure'] ?? 0.0);
        $microData->setTemperature($data['temperature'] ?? 0.0);
        $microData->setElevation($data['elevation'] ?? 0.0);
        $microData->setGpsLat($data['gps_lat'] ?? 0.0);
        $microData->setGpsLng($data['gps_lng'] ?? 0.0);
        $microData->setTimestamp(new \DateTime($data['timestamp'] ?? 'now'));
        
        // Calculate weight using regression coefficients
        $weight = $this->calculateWeight($device, $microData);
        $microData->setWeight($weight);
        
        $em->persist($microData);
        
        // Update device and vehicle timestamps if the methods exist
        if (method_exists($device, 'setLastSeen')) {
            $device->setLastSeen(new \DateTimeImmutable());
        }
        
        // Update vehicle last seen if assigned
        if ($device->getVehicle() && method_exists($device->getVehicle(), 'setLastSeen')) {
            $device->getVehicle()->setLastSeen(new \DateTimeImmutable());
        }
        
        $em->flush();
        
        return new JsonResponse([
            'status' => 'data_received',
            'calculated_weight' => $weight,
            'timestamp' => $microData->getTimestamp()->format('Y-m-d H:i:s')
        ]);
    }
    
    #[Route('/calibration/{deviceId}', name: 'get_calibration', methods: ['GET'])]
    public function getCalibration(int $deviceId, EntityManagerInterface $em): JsonResponse
    {
        $device = $em->getRepository(Device::class)->find($deviceId);
        if (!$device) {
            return new JsonResponse(['error' => 'Device not found'], 404);
        }
        
        return new JsonResponse([
            'device_id' => $device->getId(),
            'regression_coefficients' => [
                'intercept' => $device->getRegressionIntercept(),
                'air_pressure_coeff' => $device->getRegressionAirPressureCoeff(),
                'ambient_pressure_coeff' => $device->getRegressionAmbientPressureCoeff(),
                'air_temp_coeff' => $device->getRegressionAirTempCoeff(),
                'r_squared' => $device->getRegressionRsq(),
                'rmse' => $device->getRegressionRmse()
            ],
            'last_calibration' => $device->getCalibrations()->last() ? [
                'date' => $device->getCalibrations()->last()->getCreatedAt()->format('Y-m-d H:i:s'),
                'weight' => $device->getCalibrations()->last()->getScaleWeight()
            ] : null
        ]);
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
        $intercept = $device->getRegressionIntercept();
        $airPressureCoeff = $device->getRegressionAirPressureCoeff();
        $ambientPressureCoeff = $device->getRegressionAmbientPressureCoeff();
        $airTempCoeff = $device->getRegressionAirTempCoeff();
        
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