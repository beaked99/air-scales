<?php
namespace App\Controller;

use App\Entity\UserConnectedVehicle;
use App\Entity\Vehicle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class ToggleVehicleConnectionController extends AbstractController
{
    #[Route('/vehicle/{id}/toggle-connection', name: 'toggle_vehicle_connection', methods: ['POST'])]
    public function toggleConnection(Vehicle $vehicle, EntityManagerInterface $em, Request $request): JsonResponse
    {
        $user = $this->getUser();

        $connection = $em->getRepository(UserConnectedVehicle::class)
            ->findOneBy(['user' => $user, 'vehicle' => $vehicle]);

        if (!$connection) {
            $connection = new UserConnectedVehicle();
            $connection->setUser($user);
            $connection->setVehicle($vehicle);
        }

        $connection->setIsConnected(!$connection->isConnected());
        $em->persist($connection);
        $em->flush();

        return new JsonResponse(['connected' => $connection->isConnected()]);
    }
}
