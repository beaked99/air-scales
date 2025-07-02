<?php

namespace App\Controller\Api;

use App\Entity\MicroData;
use App\Repository\MicroDataRepository;
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
public function post(Request $request, LoggerInterface $logger): JsonResponse
{
    $raw = $request->getContent();
    $logger->info('Received /api/microdata POST', ['body' => $raw]);

    $data = json_decode($raw, true);
    if ($data === null) {
        $logger->error('Invalid JSON received');
        return new JsonResponse(['error' => 'Invalid JSON'], 400);
    }

    // TEMP: Log the parsed data to ensure all fields are present
    $logger->info('Parsed data', $data);

    $requiredFields = [
        'mac_address', 'main_air_pressure', 'atmospheric_pressure', 'temperature',
        'elevation', 'gps_lat', 'gps_lng', 'weight', 'timestamp'
    ];

    foreach ($requiredFields as $field) {
        if (!array_key_exists($field, $data)) {
            $logger->error("Missing required field: $field");
            return new JsonResponse(['error' => "Missing field: $field"], 400);
        }
    }

    // Continue your data processing...
    return new JsonResponse(['success' => true]);
}
    #[Route('/api/microdata/{mac}/latest', name: 'api_microdata_latest', methods: ['GET'])]
    public function latestAmbient(
        string $mac,
        MicroDataRepository $repo
    ): JsonResponse {
        $latest = $repo->createQueryBuilder('m')
            ->where('m.macAddress = :mac')
            ->setParameter('mac', $mac)
            ->orderBy('m.timestamp', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        /*if (!$latest || $latest->getTimestamp() < new \DateTimeImmutable('-20 minutes')) {
            return new JsonResponse(null);
        }
        */

        return new JsonResponse([
            'ambient' => $latest->getAtmosphericPressure(),
            'temperature' => $latest->getTemperature()
        ]);
    }

}
