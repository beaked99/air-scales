<?php

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
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return new JsonResponse(['error' => 'Invalid JSON'], 400);
        }

        // Required fields
        $requiredFields = [
            'mac_address', 'main_air_pressure', 'atmospheric_pressure',
            'temperature', 'elevation', 'gps_lat', 'gps_lng',
            'timestamp'
            // Removed 'weight' from required fields - can be calculated
        ];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                return new JsonResponse(['error' => "Missing field: $field"], 400);
            }
        }

        // 🔐 Auto-provision the device if it's missing
        $device = $deviceRepo->findOneBy(['macAddress' => $data['mac_address']]);
        if (!$device) {
            $device = new Device();
            $device->setMacAddress($data['mac_address']);
            $device->setDeviceType($data['device_type'] ?? 'ESP32');
            $device->setSerialNumber($data['serial_number'] ?? null);
            $em->persist($device);
            $em->flush(); 
        }

        // 📝 Save the microdata
        $micro = new MicroData();
        $micro->setDevice($device);
        $micro->setMacAddress($data['mac_address']);
        $micro->setMainAirPressure($data['main_air_pressure']);
        $micro->setAtmosphericPressure($data['atmospheric_pressure']);
        $micro->setTemperature($data['temperature']);
        $micro->setElevation($data['elevation']);
        $micro->setGpsLat($data['gps_lat']);
        $micro->setGpsLng($data['gps_lng']);
        $micro->setTimestamp(new \DateTimeImmutable($data['timestamp']));

        // Calculate weight using regression if available (NEW)
        $weight = $this->calculateWeight($device, $micro, $data['weight'] ?? null);
        $micro->setWeight($weight);

        $em->persist($micro);
        
        // Update device and vehicle timestamps if the methods exist
        if (method_exists($device, 'setLastSeen')) {
            $device->setLastSeen(new \DateTimeImmutable());
        }
        
        if ($device->getVehicle() && method_exists($device->getVehicle(), 'setLastSeen')) {
            $device->getVehicle()->setLastSeen(new \DateTimeImmutable());
        }
        
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'calculated_weight' => $weight,
            'device_id' => $device->getId()
        ]);
    }

    #[Route('/api/microdata/{mac}/latest', name: 'api_microdata_latest', methods: ['GET'])]
    public function latestAmbient(string $mac, EntityManagerInterface $em): JsonResponse
    {
        $latest = $em->getRepository(MicroData::class)
            ->createQueryBuilder('m')
            ->where('m.macAddress = :mac')
            ->setParameter('mac', $mac)
            ->orderBy('m.timestamp', 'DESC')
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
            'timestamp' => $latest->getTimestamp()->format('Y-m-d H:i:s')
        ]);
    }

    private function calculateWeight(Device $device, MicroData $microData, ?float $providedWeight = null): float
    {
        // If weight is provided in the data, use it (backwards compatibility)
        if ($providedWeight !== null) {
            return $providedWeight;
        }

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