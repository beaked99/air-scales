<?php
// src/Controller/DeviceConfigController.php

namespace App\Controller;

use App\Entity\Device;
use App\Entity\Vehicle;
use App\Form\AssignDeviceToVehicleType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class DeviceConfigController extends AbstractController
{
    #[Route('/dashboard/device/{id}/configure', name: 'device_configure')]
    #[IsGranted('ROLE_USER')]
    public function configure(Device $device, Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();

        // Security check
        if ($device->getSoldTo() !== $user) {
            throw $this->createAccessDeniedException('This device does not belong to you.');
        }

        $vehicle = new Vehicle();
        $vehicle->setCreatedBy($user);

        $form = $this->createForm(AssignDeviceToVehicleType::class, $vehicle);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // VIN uniqueness check
            $existingVin = $em->getRepository(Vehicle::class)
                ->findOneBy(['vin' => $vehicle->getVin()]);

            if ($existingVin) {
                $this->addFlash('error', 'A vehicle with this VIN already exists.');
            } else {
                $em->persist($vehicle);
                $device->setVehicle($vehicle);
                $em->persist($device);
                $em->flush();

                $this->addFlash('success', 'Device successfully configured and assigned to vehicle.');
                return $this->redirectToRoute('app_dashboard');
            }
        }

        return $this->render('dashboard/configure_device.html.twig', [
            'device' => $device,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/dashboard/vehicle/{id}/edit', name: 'device_vehicle_edit')]
    //#[IsGranted('ROLE_USER')]

    public function edit(Vehicle $vehicle, Request $request, EntityManagerInterface $em): Response
    {
           // dd($this->getUser(), $this->isGranted('ROLE_USER'));

        $user = $this->getUser();
        if ($vehicle->getCreatedBy() !== $user) {
            throw $this->createAccessDeniedException('FFS. You are not the owner of this vehicle or trailer. Recommend reaching out to Air Scales admin team to reassign vehicle ownership. They can either re-assign ownership or you really shouldnt be meddling here mate.');
        }

        $form = $this->createForm(AssignDeviceToVehicleType::class, $vehicle);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Vehicle updated successfully.');
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('dashboard/edit_vehicle.html.twig', [
            'form' => $form->createView(),
            'vehicle' => $vehicle,
        ]);
    }

    #[Route('/dashboard/vehicle/{id}/delete', name: 'device_vehicle_delete', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function delete(Vehicle $vehicle, Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();

        if ($vehicle->getCreatedBy() !== $user) {
            throw $this->createAccessDeniedException();
        }

        if ($this->isCsrfTokenValid('delete'.$vehicle->getId(), $request->request->get('_token'))) {
            foreach ($vehicle->getDevices() as $device) {
                $device->setVehicle(null);
            }
            $em->remove($vehicle);
            $em->flush();
            $this->addFlash('success', 'Vehicle deleted successfully.');
        }

        return $this->redirectToRoute('app_dashboard');
    }
}
