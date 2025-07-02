<?php

namespace App\Controller\Api;

use App\Entity\MicroData;
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
            'weight', 'timestamp'
        ];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                return new JsonResponse(['error' => "Missing field: $field"], 400);
            }
        }

        // 🔐 Auto-provision the device if it's missing
        $device = $deviceRepo->findOneBy(['macAddress' => $data['mac_address']]);
        if (!$device) {
            $device = new \App\Entity\Device();
            $device->setMacAddress($data['mac_address']);
            $device->setName('Auto-registered ESP32');
            $device->setCreatedAt(new \DateTimeImmutable());
            $em->persist($device);
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
        $micro->setWeight($data['weight']);
        $micro->setTimestamp(new \DateTimeImmutable($data['timestamp']));

        $em->persist($micro);
        $em->flush();

        return new JsonResponse(['success' => true]);
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
            'temperature' => $latest->getTemperature()
        ]);
    }
}
