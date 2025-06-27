<?php
namespace App\Controller;

use App\Form\VehicleType;
use Symfony\Component\HttpFoundation\Request;
use App\Entity\Device;
use App\Entity\DeviceAccess;
use App\Entity\Vehicle;
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

        // 1. Devices the user connected to
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
            $device = $record->getDevice();
            $userDevices[$device->getId()] = $device;
        }

        // 2. Devices the user purchased
        $purchasedDevices = $em->getRepository(Device::class)
            ->findBy(['soldTo' => $user]);

        foreach ($purchasedDevices as $device) {
            $userDevices[$device->getId()] = $device; // ✅ merge properly here
        }
        // Vehicles the user created
        $ownedVehicles = $em->getRepository(Vehicle::class)
            ->findBy(['created_by' => $user]);

        $vehicleSet = [];

        // Collect from devices connected via DeviceAccess
        foreach ($userDevices as $device) {
            if ($device->getVehicle()) {
                $vehicleSet[$device->getVehicle()->getId()] = $device->getVehicle();
            }
        }

        // Add vehicles created by user
        foreach ($ownedVehicles as $vehicle) {
            $vehicleSet[$vehicle->getId()] = $vehicle;
        }

        return $this->render('dashboard/index.html.twig', [
            'devices' => $userDevices,
            'accessRecords' => $accessRecords,
            'vehicles' => $vehicleSet,
        ]);
    }

    #[Route('/dashboard/device/unlink/{id}', name: 'unlink_device')]
    public function unlink(Device $device, EntityManagerInterface $em): Response
    {
        $access = $em->getRepository(DeviceAccess::class)
            ->findOneBy(['user' => $this->getUser(), 'device' => $device]);

        if ($access && $access->isActive()) {
            $access->setIsActive(false);
            $em->flush();
        }

        return $this->redirectToRoute('app_dashboard');
    }
    

}
