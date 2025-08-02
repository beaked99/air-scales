<?php
//src/Controller/DashboardController.php
namespace App\Controller;

use App\Entity\Device;
use App\Entity\DeviceAccess;
use App\Entity\MicroData;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;


class DashboardController extends AbstractController
{
        
    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        
        // Get user's devices (using your existing logic)
        $userDevices = $this->getUserDevices($em, $user);
        
        return $this->render('dashboard/index.html.twig', [
            'devices' => $userDevices
        ]);
    }

    private function getUserDevices(EntityManagerInterface $em, $user): array
{
    // Your existing device access logic...
    $accessRecords = $em->getRepository(DeviceAccess::class)
        ->createQueryBuilder('a')
        ->leftJoin('a.device', 'd')
        ->addSelect('d')
        ->where('a.user = :user')
        ->andWhere('a.isActive = true')
        ->setParameter('user', $user)
        ->getQuery()
        ->getResult();

    $userDevices = [];
    foreach ($accessRecords as $record) {
        $userDevices[$record->getDevice()->getId()] = $record->getDevice();
    }

    // Add purchased devices
    $purchasedDevices = $em->getRepository(Device::class)->findBy(['soldTo' => $user]);
    foreach ($purchasedDevices as $device) {
        $userDevices[$device->getId()] = $device;
    }
    
    // 🆕 NEW: Add latest sensor data to each device
    foreach ($userDevices as $device) {
        $latestData = $em->getRepository(MicroData::class)
            ->createQueryBuilder('m')
            ->where('m.device = :device')
            ->setParameter('device', $device)
            ->orderBy('m.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
            
        // Add as a property we can access in Twig
        $device->latestMicroData = $latestData;
    }
    
    return array_values($userDevices);
}
}