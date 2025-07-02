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
    dd('fuck this');
    file_put_contents('/tmp/microdata.log', $request->getContent() . "\n", FILE_APPEND);
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
