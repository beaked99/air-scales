<?php

//CalibrationDashboardController.php


namespace App\Controller;

use App\Entity\Calibration;
use App\Entity\Device;
use App\Entity\DeviceAccess;
use App\Entity\User;
use App\Form\CalibrationType;
use App\Service\DeviceCalibrationRegressor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class CalibrationDashboardController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private DeviceCalibrationRegressor $regressor
    ) {}

    #[Route('/dashboard/device/{id}/calibration', name: 'dashboard_device_calibration')]
    #[IsGranted('ROLE_USER')]
    public function calibrate(Device $device, Request $request): Response
    {
        $user = $this->getUser();
        $hasAccess = false;

        // Is this their purchased device?
        if ($device->getSoldTo() === $user) {
            $hasAccess = true;
        }

        // Did they ever connect to it?
        foreach ($device->getDeviceAccesses() as $access) {
            if ($access->getUser() === $user && $access->isActive()) {
                $hasAccess = true;
                break;
            }
        }

        if (!$hasAccess) {
            throw $this->createAccessDeniedException("FFS bro. You do not have permission to calibrate or see this device.");
        }

        $calibration = new Calibration();
        $calibration->setDevice($device);
        $calibration->setCreatedBy($user);

        $form = $this->createForm(CalibrationType::class, $calibration);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($calibration);
            $this->em->flush();

            // Re-run model after new calibration
            $this->regressor->run($device);

            $this->addFlash('success', 'Calibration entry saved.');
            return $this->redirectToRoute('dashboard_device_calibration', [
                'id' => $device->getId(),
                '_fragment' => 'chart' // optional scroll anchor            
            ]);
        }

        $calibrations = $this->em->getRepository(Calibration::class)
            ->findBy(['device' => $device], ['created_at' => 'DESC']);

        return $this->render('dashboard/calibration.html.twig', [
            'device' => $device,
            'form' => $form->createView(),
            'calibrations' => $calibrations,
            'coefficients' => [
                'intercept' => $device->getRegressionIntercept() ?? 0,
                'airPressure' => $device->getRegressionAirPressureCoeff() ?? 0,
                'ambientPressure' => $device->getRegressionAmbientPressureCoeff() ?? 0,
                'temperature' => $device->getRegressionAirTempCoeff() ?? 0,
            ],
        ]);
    }

    #[Route('/dashboard/calibration/delete/{id}', name: 'dashboard_calibration_delete', methods: ['POST'])]
    public function deleteCalibration(Calibration $cal): JsonResponse
    {
        $device = $cal->getDevice();

        $this->em->remove($cal);
        $this->em->flush();

        // Re-run model after deletion
        $this->regressor->run($device);

        return new JsonResponse(['status' => 'deleted']);
    }
}
