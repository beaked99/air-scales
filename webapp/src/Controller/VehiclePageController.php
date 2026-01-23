<?php

namespace App\Controller;

use App\Entity\Vehicle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class VehiclePageController extends AbstractController
{
    #[Route('/vehicles', name: 'vehicles')]
    public function index(EntityManagerInterface $em): Response
    {
        $user = $this->getUser();

        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        // Get vehicles this user has connected to OR created
        $qb = $em->createQueryBuilder();
        $qb->select('v')
           ->from(Vehicle::class, 'v')
           ->leftJoin('v.created_by', 'creator')
           ->leftJoin('App\Entity\UserConnectedVehicle', 'ucv', 'WITH', 'ucv.vehicle = v')
           ->where('creator = :user OR (ucv.user = :user AND ucv.isConnected = true)')
           ->setParameter('user', $user)
           ->orderBy('v.updated_at', 'DESC');

        $vehicles = $qb->getQuery()->getResult();

        return $this->render('vehicle/index.html.twig', [
            'vehicles' => $vehicles
        ]);
    }

    #[Route('/vehicle/{id}', name: 'vehicle_detail')]
    public function detail(int $id, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();

        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $vehicle = $em->getRepository(Vehicle::class)->find($id);

        if (!$vehicle) {
            throw $this->createNotFoundException('Vehicle not found');
        }

        // Check if user owns vehicle OR has connected to it
        $isOwner = $vehicle->getCreatedBy() === $user;
        $isConnected = $em->getRepository(\App\Entity\UserConnectedVehicle::class)->findOneBy([
            'user' => $user,
            'vehicle' => $vehicle,
            'isConnected' => true
        ]);

        if (!$isOwner && !$isConnected) {
            throw $this->createAccessDeniedException('You do not have access to this vehicle');
        }

        return $this->render('vehicle/detail.html.twig', [
            'vehicle' => $vehicle
        ]);
    }
}
