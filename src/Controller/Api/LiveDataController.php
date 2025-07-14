<?php

namespace App\Controller\Api;

use App\Entity\Device;
use App\Entity\MicroData;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api')]
class LiveDataController extends AbstractController
{
    #[Route('/devices/live-data', name: 'api_devices_live_data', methods: ['GET'])]
    public function getAllDevicesLiveData(EntityManagerInterface $em): JsonResponse
    {
        // For testing, get all devices
        $devices = $em->getRepository(Device::class)->findAll();
        
        $liveData = [];
        
        foreach ($devices as $device) {
            // Get latest sensor data for each device
            $latestData = $em->getRepository(MicroData::class)
                ->createQueryBuilder('m')
                ->where('m.device = :device')
                ->setParameter('device', $device)
                ->orderBy('m.timestamp', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
            
            if ($latestData) {
                $liveData[] = [
                    'device_id' => $device->getId(),
                    'device_name' => $device->getSerialNumber() ?: ('Device #' . $device->getId()),
                    'mac_address' => $device->getMacAddress(),
                    'weight' => $latestData->getWeight(),
                    'main_air_pressure' => $latestData->getMainAirPressure(),
                    'temperature' => $latestData->getTemperature(),
                    'timestamp' => $latestData->getTimestamp()->format('Y-m-d H:i:s'),
                    'vehicle' => $device->getVehicle() ? $device->getVehicle()->__toString() : null,
                    'status' => 'online',
                    'last_seen' => $latestData->getTimestamp()->diff(new \DateTime())->format('%i minutes ago')
                ];
            }
        }
        
        return new JsonResponse([
            'devices' => $liveData,
            'total_weight' => array_sum(array_column($liveData, 'weight')),
            'device_count' => count($liveData),
            'last_updated' => (new \DateTime())->format('Y-m-d H:i:s')
        ]);
    }
}