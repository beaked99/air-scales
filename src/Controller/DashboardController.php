<?php
namespace App\Controller;

use App\Entity\Device;
use App\Entity\DeviceAccess;
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

        // 1. Devices the user connected to (via access records)
        $accessRecords = $em->getRepository(DeviceAccess::class)
            ->createQueryBuilder('a')
            ->leftJoin('a.device', 'd')
            ->addSelect('d')
            ->where('a.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        $connectedDevices = [];
        foreach ($accessRecords as $record) {
            $connectedDevices[$record->getDevice()->getId()] = $record->getDevice();
        }

        // 2. Devices the user purchased (via soldTo)
        $purchasedDevices = $em->getRepository(Device::class)
            ->findBy(['soldTo' => $user]);

        foreach ($purchasedDevices as $device) {
            $connectedDevices[$device->getId()] = $device; // merge w/o duplicates
        }

        return $this->render('dashboard/index.html.twig', [
            'devices' => $connectedDevices,
            'accessRecords' => $accessRecords,
        ]);
    }

    #[Route('/dashboard/device/unlink/{id}', name: 'unlink_device')]
    public function unlink(Device $device, EntityManagerInterface $em): Response
    {
        $access = $em->getRepository(DeviceAccess::class)
            ->findOneBy(['user' => $this->getUser(), 'device' => $device]);

        if ($access) {
            $em->remove($access);
            $em->flush();
        }

        return $this->redirectToRoute('app_dashboard');
    }
}
