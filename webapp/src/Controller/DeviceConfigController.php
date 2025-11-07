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

    #[Route('/dashboard/device/{id}/unassign', name: 'device_unassign', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function unassignDevice(Device $device, Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        
        // Check if user owns the vehicle this device is assigned to
        if (!$device->getVehicle() || $device->getVehicle()->getCreatedBy() !== $user) {
            throw $this->createAccessDeniedException('You can only unassign devices from your own vehicles.');
        }

        if ($this->isCsrfTokenValid('unassign'.$device->getId(), $request->request->get('_token'))) {
            $device->setVehicle(null);
            $em->flush();
            $this->addFlash('success', 'Device unassigned successfully.');
        }

        return $this->redirectToRoute('device_vehicle_edit', ['id' => $request->headers->get('referer') ? $device->getVehicle()->getId() : null]) 
            ?: $this->redirectToRoute('app_dashboard');
    }

    #[Route('/dashboard/vehicle/{vehicle_id}/assign-device', name: 'device_assign_to_vehicle', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function assignDeviceToVehicle(int $vehicle_id, Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        $vehicle = $em->getRepository(Vehicle::class)->find($vehicle_id);
        
        if (!$vehicle || $vehicle->getCreatedBy() !== $user) {
            throw $this->createAccessDeniedException('Vehicle not found or not owned by you.');
        }

        $serialNumber = $request->request->get('device_serial');
        $device = $em->getRepository(Device::class)->findOneBy(['serialNumber' => $serialNumber]);
        
        if (!$device) {
            $this->addFlash('error', 'Device with serial number not found.');
        } elseif ($device->getSoldTo() !== $user) {
            $this->addFlash('error', 'This device is not sold to you.');
        } elseif ($device->getVehicle()) {
            $this->addFlash('error', 'Device is already assigned to another vehicle.');
        } else {
            $device->setVehicle($vehicle);
            $em->flush();
            $this->addFlash('success', 'Device assigned successfully.');
        }

        return $this->redirectToRoute('device_vehicle_edit', ['id' => $vehicle_id]);
    }
}
