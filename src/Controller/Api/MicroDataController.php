<?php

namespace App\Controller\Api;

use App\Entity\MicroData;
use App\Repository\DeviceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class MicroDataController extends AbstractController
{
    #[Route('/api/microdata', name: 'api_microdata', methods: ['POST'])]
    
    public function receive(
        Request $request,
        EntityManagerInterface $em,
        DeviceRepository $deviceRepository
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        if ($data === null) {
            return new JsonResponse(['error' => 'Invalid fkn JSON'], 400);
        }

        // Check for required fields
        $requiredFields = [
            'mac_address', 'main_air_pressure', 'atmospheric_pressure', 'temperature',
            'elevation', 'gps_lat', 'gps_lng', 'weight', 'timestamp'
        ];

        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                return new JsonResponse(['error' => "Missing field: $field"], 400);
            }
        }

        // Find device by MAC Address
        $device = $deviceRepository->findOneBy(['mac_address' => $data['mac_address']]);
        if (!$device) {
            return new JsonResponse(['error' => 'Device not registered'], 404);
        }
        // Create and populate MicroData entity
        $microData = new MicroData();
        $microData->setDevice($device);
        $microData->setMacAddress($data['mac_address']);
        $microData->setMainAirPressure((float) $data['main_air_pressure']);
        $microData->setAtmosphericPressure((float) $data['atmospheric_pressure']);
        $microData->setTemperature((float) $data['temperature']);
        $microData->setElevation((float) $data['elevation']);
        $microData->setGpsLat((float) $data['gps_lat']);
        $microData->setGpsLng((float) $data['gps_lng']);
        $microData->setWeight((float) $data['weight']);
        $microData->setTimestamp(new \DateTime($data['timestamp']));

        $em->persist($microData);
        $em->flush();

        return new JsonResponse(['status' => 'success']);
    }
}
