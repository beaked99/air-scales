<?php
namespace App\Controller;

use App\Form\VehicleType;
use Symfony\Component\HttpFoundation\Request;
use App\Entity\Device;
use App\Entity\DeviceAccess;
use App\Entity\Vehicle;
use App\Entity\UserVehicleOrder;
use App\Entity\UserConnectedVehicle;

use Symfony\Component\HttpFoundation\JsonResponse;
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
        // Get vehicles for this user, ordered by drag-and-drop preference
        $order = $em->getRepository(UserVehicleOrder::class)
            ->findBy(['user' => $user], ['position' => 'ASC']);

        $vehicleSet = [];

        foreach ($order as $vOrder) {
            $vehicle = $vOrder->getVehicle();
            if ($vehicle) {
                $vehicleSet[$vehicle->getId()] = $vehicle;
            }
        }

        // Also add vehicles seen via device connection (if not already in order)
        foreach ($userDevices as $device) {
            if ($device->getVehicle()) {
                $vehicleSet[$device->getVehicle()->getId()] = $device->getVehicle();
            }
        }
        $connections = $em->getRepository(UserConnectedVehicle::class)
            ->findBy(['user' => $user]);

        $connectedMap = [];
        foreach ($connections as $conn) {
            $vehicle = $conn->getVehicle();
            if ($vehicle) {
                $connectedMap[$vehicle->getId()] = $conn->isConnected();
            }
        }

        return $this->render('dashboard/index.html.twig', [
            'devices' => $userDevices,
            'accessRecords' => $accessRecords,
            'vehicles' => $vehicleSet,
            'connectedMap' => $connectedMap,
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
    #[Route('/dashboard/vehicle/reorder', name: 'vehicle_sort_user', methods: ['POST'])]
    public function reorderVehicles(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $ids = $data['order'] ?? [];

        foreach ($ids as $position => $vehicleId) {
            $vehicle = $em->getRepository(Vehicle::class)->find($vehicleId);
            if (!$vehicle) continue;

            $order = $em->getRepository(UserVehicleOrder::class)
                ->findOneBy(['user' => $this->getUser(), 'vehicle' => $vehicle]);

            if (!$order) {
                $order = new UserVehicleOrder();
                $order->setUser($this->getUser());
                $order->setVehicle($vehicle);
            }

            $order->setPosition($position);
            $em->persist($order);
        }

        $em->flush();
        return new JsonResponse(['status' => 'ok']);
    }


}
