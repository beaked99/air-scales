<?php

namespace App\Controller;

use App\Entity\Calibration;
use App\Entity\Device;
use App\Form\CalibrationType;
use App\Service\DeviceCalibrationRegressor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/calibration')]
class CalibrationController extends AbstractController
{
    #[Route('/device/{id}', name: 'device_calibration', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function calibrateDevice(Device $device, Request $request, EntityManagerInterface $em, DeviceCalibrationRegressor $regressor): Response
    {
        $user = $this->getUser();
        
        // Check if user has access to this device
        $hasAccess = $device->getSoldTo() === $user || 
                     $em->getRepository(\App\Entity\DeviceAccess::class)->findOneBy([
                         'device' => $device,
                         'user' => $user,
                         'isActive' => true
                     ]);
        
        if (!$hasAccess) {
            throw $this->createAccessDeniedException('You do not have access to this device.');
        }
        
        // Get latest sensor data for pre-filling
        $latestData = $em->getRepository(\App\Entity\MicroData::class)
            ->createQueryBuilder('m')
            ->where('m.device = :device')
            ->setParameter('device', $device)
            ->orderBy('m.timestamp', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        
        $calibration = new Calibration();
        $calibration->setDevice($device);
        // Set the user who created this calibration
        $calibration->setCreatedBy($user);
        
        // Pre-fill with latest sensor data if available
        if ($latestData) {
            $calibration->setAirPressure($latestData->getMainAirPressure());
            $calibration->setAmbientAirPressure($latestData->getAtmosphericPressure());
            $calibration->setAirTemperature($latestData->getTemperature());
            $calibration->setElevation($latestData->getElevation());
        }
        
        $form = $this->createForm(CalibrationType::class, $calibration);
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($calibration);
            $em->flush();
            
            // Run regression analysis
            $regressionSuccess = $regressor->run($device);
            
            if ($regressionSuccess) {
                $this->addFlash('success', 'Calibration data saved and regression model updated successfully!');
            } else {
                $this->addFlash('warning', 'Calibration data saved, but more data points are needed for regression analysis.');
            }
            
            return $this->redirectToRoute('device_calibration_history', ['id' => $device->getId()]);
        }
        
        return $this->render('calibration/calibrate.html.twig', [
            'device' => $device,
            'form' => $form,
            'latestData' => $latestData,
            'calibrationCount' => $device->getCalibrations()->count()
        ]);
    }
    
    #[Route('/device/{id}/history', name: 'device_calibration_history')]
    #[IsGranted('ROLE_USER')]
    public function calibrationHistory(Device $device, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        
        // Check access
        $hasAccess = $device->getSoldTo() === $user || 
                     $em->getRepository(\App\Entity\DeviceAccess::class)->findOneBy([
                         'device' => $device,
                         'user' => $user,
                         'isActive' => true
                     ]);
        
        if (!$hasAccess) {
            throw $this->createAccessDeniedException('You do not have access to this device.');
        }
        
        $calibrations = $em->getRepository(Calibration::class)
            ->createQueryBuilder('c')
            ->where('c.device = :device')
            ->setParameter('device', $device)
            ->orderBy('c.created_at', 'DESC')  // Using created_at from TimestampableTrait
            ->getQuery()
            ->getResult();
        
        return $this->render('calibration/history.html.twig', [
            'device' => $device,
            'calibrations' => $calibrations,
            'regressionCoeffs' => [
                'intercept' => $device->getRegressionIntercept(),
                'airPressure' => $device->getRegressionAirPressureCoeff(),
                'ambientPressure' => $device->getRegressionAmbientPressureCoeff(),
                'airTemp' => $device->getRegressionAirTempCoeff(),
                'rSquared' => $device->getRegressionRsq(),
                'rmse' => $device->getRegressionRmse()
            ]
        ]);
    }
    
    #[Route('/api/device/{id}/live-data', name: 'api_device_live_data', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getLiveData(Device $device, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        
        // Check access
        $hasAccess = $device->getSoldTo() === $user || 
                     $em->getRepository(\App\Entity\DeviceAccess::class)->findOneBy([
                         'device' => $device,
                         'user' => $user,
                         'isActive' => true
                     ]);
        
        if (!$hasAccess) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }
        
        // Get latest data
        $latestData = $em->getRepository(\App\Entity\MicroData::class)
            ->createQueryBuilder('m')
            ->where('m.device = :device')
            ->setParameter('device', $device)
            ->orderBy('m.timestamp', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        
        if (!$latestData) {
            return new JsonResponse(['error' => 'No data available'], 404);
        }
        
        return new JsonResponse([
            'device_id' => $device->getId(),
            'mac_address' => $device->getMacAddress(),
            'timestamp' => $latestData->getTimestamp()->format('Y-m-d H:i:s'),
            'main_air_pressure' => $latestData->getMainAirPressure(),
            'atmospheric_pressure' => $latestData->getAtmosphericPressure(),
            'temperature' => $latestData->getTemperature(),
            'elevation' => $latestData->getElevation(),
            'gps_lat' => $latestData->getGpsLat(),
            'gps_lng' => $latestData->getGpsLng(),
            'weight' => $latestData->getWeight(),
            'vehicle' => $device->getVehicle() ? $device->getVehicle()->__toString() : null
        ]);
    }
    
    #[Route('/api/device/{id}/force-regression', name: 'api_force_regression', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function forceRegression(Device $device, EntityManagerInterface $em, DeviceCalibrationRegressor $regressor): JsonResponse
    {
        $user = $this->getUser();
        
        // Check access (only device owner can force regression)
        if ($device->getSoldTo() !== $user) {
            return new JsonResponse(['error' => 'Only device owner can force regression'], 403);
        }
        
        $success = $regressor->run($device);
        
        if ($success) {
            return new JsonResponse([
                'status' => 'success',
                'message' => 'Regression analysis completed',
                'coefficients' => [
                    'intercept' => $device->getRegressionIntercept(),
                    'air_pressure_coeff' => $device->getRegressionAirPressureCoeff(),
                    'ambient_pressure_coeff' => $device->getRegressionAmbientPressureCoeff(),
                    'air_temp_coeff' => $device->getRegressionAirTempCoeff(),
                    'r_squared' => $device->getRegressionRsq(),
                    'rmse' => $device->getRegressionRmse()
                ]
            ]);
        }
        
        return new JsonResponse([
            'status' => 'error',
            'message' => 'Not enough calibration data for regression analysis (minimum 2 points required)'
        ], 400);
    }
}