<?php

namespace App\Controller;

use App\Entity\Vehicle;
use App\Entity\Device;
use App\Entity\DeviceAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Psr\Log\LoggerInterface;

#[Route('/api/vehicle', name: 'api_vehicle_')]
class VehicleController extends AbstractController
{
    #[Route('/user-vehicles', name: 'user_vehicles', methods: ['GET'])]
    public function getUserVehicles(EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
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

        $vehicleData = [];
        foreach ($vehicles as $vehicle) {
            $vehicleData[] = [
                'id' => $vehicle->getId(),
                'name' => $vehicle->__toString(),
                'year' => $vehicle->getYear(),
                'make' => $vehicle->getMake(),
                'model' => $vehicle->getModel(),
                'nickname' => $vehicle->getNickname(),
                'device_count' => $vehicle->getDevices()->count()
            ];
        }

        return new JsonResponse([
            'vehicles' => $vehicleData,
            'count' => count($vehicleData)
        ]);
    }
    
    #[Route('/create', name: 'create', methods: ['POST'])]
    public function createVehicle(
        Request $request,
        EntityManagerInterface $em,
        LoggerInterface $logger
    ): JsonResponse {
        try {
            $user = $this->getUser();
            
            if (!$user) {
                return new JsonResponse(['error' => 'Not authenticated'], 401);
            }
            
            $data = json_decode($request->getContent(), true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse(['error' => 'Invalid JSON'], 400);
            }
            
            // Validate required fields
            if (!isset($data['year']) || !isset($data['make']) || !isset($data['model'])) {
                return new JsonResponse(['error' => 'Year, make, and model are required'], 400);
            }
            
            $logger->info('Creating new vehicle for user', [
                'user_id' => $user->getId(),
                'data' => $data
            ]);
            
            $vehicle = new Vehicle();
            $vehicle->setYear((int)$data['year']);
            $vehicle->setMake($data['make']);
            $vehicle->setModel($data['model']);
            $vehicle->setNickname($data['nickname'] ?? null);
            $vehicle->setVin($data['vin'] ?? null);
            $vehicle->setLicensePlate($data['license_plate'] ?? null);
            $vehicle->setCreatedBy($user);
            $vehicle->setUpdatedBy($user);
            
            $em->persist($vehicle);
            $em->flush();
            
            $logger->info('Vehicle created successfully', [
                'vehicle_id' => $vehicle->getId()
            ]);
            
            return new JsonResponse([
                'success' => true,
                'vehicle' => [
                    'id' => $vehicle->getId(),
                    'name' => $vehicle->__toString(),
                    'year' => $vehicle->getYear(),
                    'make' => $vehicle->getMake(),
                    'model' => $vehicle->getModel(),
                    'nickname' => $vehicle->getNickname()
                ]
            ]);
            
        } catch (\Exception $e) {
            $logger->error('Vehicle creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return new JsonResponse([
                'error' => 'Vehicle creation failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    #[Route('/assign-device/{deviceId}', name: 'assign_device', methods: ['POST'])]
    public function assignDeviceToVehicle(
        int $deviceId,
        Request $request,
        EntityManagerInterface $em,
        LoggerInterface $logger
    ): JsonResponse {
        try {
            $user = $this->getUser();

            if (!$user) {
                return new JsonResponse(['error' => 'Not authenticated'], 401);
            }

            $data = json_decode($request->getContent(), true);
            $vehicleId = $data['vehicle_id'] ?? null;

            if (!$vehicleId) {
                return new JsonResponse(['error' => 'vehicle_id required'], 400);
            }

            // Get device and verify user has access
            $device = $em->getRepository(Device::class)->find($deviceId);
            if (!$device) {
                return new JsonResponse(['error' => 'Device not found'], 404);
            }

            $deviceAccess = $em->getRepository(DeviceAccess::class)->findOneBy([
                'device' => $device,
                'user' => $user
            ]);

            if (!$deviceAccess) {
                return new JsonResponse(['error' => 'Access denied'], 403);
            }

            // Get vehicle and verify ownership
            $vehicle = $em->getRepository(Vehicle::class)->find($vehicleId);
            if (!$vehicle) {
                return new JsonResponse(['error' => 'Vehicle not found'], 404);
            }

            if ($vehicle->getCreatedBy() !== $user) {
                return new JsonResponse(['error' => 'You do not own this vehicle'], 403);
            }

            $logger->info('Assigning device to vehicle', [
                'device_id' => $deviceId,
                'vehicle_id' => $vehicleId,
                'user_id' => $user->getId()
            ]);

            // Assign device to vehicle
            $device->setVehicle($vehicle);
            $em->flush();

            $logger->info('Device assigned successfully');

            return new JsonResponse([
                'success' => true,
                'device_id' => $device->getId(),
                'vehicle_id' => $vehicle->getId(),
                'vehicle_name' => $vehicle->__toString()
            ]);

        } catch (\Exception $e) {
            $logger->error('Device assignment failed', [
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'error' => 'Assignment failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    #[Route('/detach-device/{deviceId}', name: 'detach_device', methods: ['POST'])]
    public function detachDeviceFromVehicle(
        int $deviceId,
        EntityManagerInterface $em,
        LoggerInterface $logger
    ): JsonResponse {
        try {
            $user = $this->getUser();

            if (!$user) {
                return new JsonResponse(['error' => 'Not authenticated'], 401);
            }

            // Get device and verify user has access
            $device = $em->getRepository(Device::class)->find($deviceId);
            if (!$device) {
                return new JsonResponse(['error' => 'Device not found'], 404);
            }

            $deviceAccess = $em->getRepository(DeviceAccess::class)->findOneBy([
                'device' => $device,
                'user' => $user
            ]);

            if (!$deviceAccess) {
                return new JsonResponse(['error' => 'Access denied'], 403);
            }

            $vehicleName = $device->getVehicle() ? $device->getVehicle()->__toString() : 'None';

            $logger->info('Detaching device from vehicle', [
                'device_id' => $deviceId,
                'vehicle' => $vehicleName,
                'user_id' => $user->getId()
            ]);

            // CRITICAL: Delete all calibration data when device is detached from vehicle
            // Calibration is vehicle-specific and should not transfer to another vehicle
            $calibrations = $device->getCalibrations();
            $calibrationCount = $calibrations->count();

            foreach ($calibrations as $calibration) {
                $em->remove($calibration);
            }

            // Clear regression coefficients
            $device->setRegressionIntercept(null);
            $device->setRegressionAirPressureCoeff(null);
            $device->setRegressionAmbientPressureCoeff(null);
            $device->setRegressionAirTempCoeff(null);
            $device->setRegressionRsq(null);
            $device->setRegressionRmse(null);

            // Detach from vehicle
            $device->setVehicle(null);

            $em->flush();

            $logger->info('Device detached successfully', [
                'calibrations_deleted' => $calibrationCount
            ]);

            return new JsonResponse([
                'success' => true,
                'device_id' => $device->getId(),
                'calibrations_deleted' => $calibrationCount,
                'message' => "Device detached from {$vehicleName}. All calibration data has been cleared."
            ]);

        } catch (\Exception $e) {
            $logger->error('Device detachment failed', [
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'error' => 'Detachment failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    #[Route('/update/{id}', name: 'update', methods: ['PUT', 'POST'])]
    public function updateVehicle(
        int $id,
        Request $request,
        EntityManagerInterface $em,
        LoggerInterface $logger
    ): JsonResponse {
        try {
            $user = $this->getUser();

            if (!$user) {
                return new JsonResponse(['error' => 'Not authenticated'], 401);
            }

            $vehicle = $em->getRepository(Vehicle::class)->find($id);
            if (!$vehicle) {
                return new JsonResponse(['error' => 'Vehicle not found'], 404);
            }

            // Check if user owns vehicle OR has connected to it
            $isOwner = $vehicle->getCreatedBy() === $user;
            $isConnected = $em->getRepository(\App\Entity\UserConnectedVehicle::class)->findOneBy([
                'user' => $user,
                'vehicle' => $vehicle,
                'isConnected' => true
            ]);

            if (!$isOwner && !$isConnected) {
                return new JsonResponse(['error' => 'Access denied - you do not have access to this vehicle'], 403);
            }

            $data = json_decode($request->getContent(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse(['error' => 'Invalid JSON'], 400);
            }

            $logger->info('Updating vehicle', [
                'vehicle_id' => $id,
                'user_id' => $user->getId(),
                'data' => $data
            ]);

            // Update fields if provided
            if (isset($data['year'])) {
                $vehicle->setYear((int)$data['year']);
            }
            if (isset($data['make'])) {
                $vehicle->setMake($data['make']);
            }
            if (isset($data['model'])) {
                $vehicle->setModel($data['model']);
            }
            if (isset($data['nickname'])) {
                $vehicle->setNickname($data['nickname']);
            }
            if (isset($data['vin'])) {
                $vehicle->setVin($data['vin']);
            }
            if (isset($data['license_plate'])) {
                $vehicle->setLicensePlate($data['license_plate']);
            }

            $vehicle->setUpdatedBy($user);
            $em->flush();

            $logger->info('Vehicle updated successfully');

            return new JsonResponse([
                'success' => true,
                'vehicle' => [
                    'id' => $vehicle->getId(),
                    'name' => $vehicle->__toString(),
                    'year' => $vehicle->getYear(),
                    'make' => $vehicle->getMake(),
                    'model' => $vehicle->getModel(),
                    'nickname' => $vehicle->getNickname(),
                    'vin' => $vehicle->getVin(),
                    'license_plate' => $vehicle->getLicensePlate()
                ]
            ]);

        } catch (\Exception $e) {
            $logger->error('Vehicle update failed', [
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'error' => 'Update failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    #[Route('/delete/{id}', name: 'delete', methods: ['DELETE', 'POST'])]
    public function deleteVehicle(
        int $id,
        EntityManagerInterface $em,
        LoggerInterface $logger
    ): JsonResponse {
        try {
            $user = $this->getUser();

            if (!$user) {
                return new JsonResponse(['error' => 'Not authenticated'], 401);
            }

            $vehicle = $em->getRepository(Vehicle::class)->find($id);
            if (!$vehicle) {
                return new JsonResponse(['error' => 'Vehicle not found'], 404);
            }

            // Only vehicle owner can delete
            if ($vehicle->getCreatedBy() !== $user) {
                return new JsonResponse(['error' => 'You do not own this vehicle'], 403);
            }

            // Check if vehicle has devices attached
            if ($vehicle->getDevices()->count() > 0) {
                return new JsonResponse([
                    'error' => 'Cannot delete vehicle with devices attached',
                    'device_count' => $vehicle->getDevices()->count(),
                    'message' => 'Please detach all devices before deleting this vehicle'
                ], 400);
            }

            $vehicleName = $vehicle->__toString();

            $logger->info('Deleting vehicle', [
                'vehicle_id' => $id,
                'vehicle_name' => $vehicleName,
                'user_id' => $user->getId()
            ]);

            $em->remove($vehicle);
            $em->flush();

            $logger->info('Vehicle deleted successfully');

            return new JsonResponse([
                'success' => true,
                'message' => "Vehicle '{$vehicleName}' has been deleted"
            ]);

        } catch (\Exception $e) {
            $logger->error('Vehicle deletion failed', [
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'error' => 'Deletion failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    #[Route('/{id}', name: 'get', methods: ['GET'])]
    public function getVehicle(
        int $id,
        EntityManagerInterface $em
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        $vehicle = $em->getRepository(Vehicle::class)->find($id);
        if (!$vehicle) {
            return new JsonResponse(['error' => 'Vehicle not found'], 404);
        }

        // Check if user owns vehicle OR has connected to it
        $isOwner = $vehicle->getCreatedBy() === $user;
        $isConnected = $em->getRepository(\App\Entity\UserConnectedVehicle::class)->findOneBy([
            'user' => $user,
            'vehicle' => $vehicle,
            'isConnected' => true
        ]);

        if (!$isOwner && !$isConnected) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }

        $devices = [];
        foreach ($vehicle->getDevices() as $device) {
            $devices[] = [
                'id' => $device->getId(),
                'serial_number' => $device->getSerialNumber(),
                'mac_address' => $device->getMacAddress(),
                'device_type' => $device->getDeviceType(),
                'calibration_count' => $device->getCalibrations()->count(),
                'has_regression' => $device->getRegressionIntercept() !== null
            ];
        }

        return new JsonResponse([
            'vehicle' => [
                'id' => $vehicle->getId(),
                'year' => $vehicle->getYear(),
                'make' => $vehicle->getMake(),
                'model' => $vehicle->getModel(),
                'nickname' => $vehicle->getNickname(),
                'vin' => $vehicle->getVin(),
                'license_plate' => $vehicle->getLicensePlate(),
                'name' => $vehicle->__toString(),
                'created_at' => $vehicle->getCreatedAt()?->format('Y-m-d H:i:s'),
                'updated_at' => $vehicle->getUpdatedAt()?->format('Y-m-d H:i:s'),
                'last_seen' => $vehicle->getLastSeen()?->format('Y-m-d H:i:s'),
                'device_count' => count($devices),
                'devices' => $devices
            ]
        ]);
    }

    #[Route('/search', name: 'search', methods: ['GET'])]
    public function searchVehicles(
        Request $request,
        EntityManagerInterface $em
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        $query = $request->query->get('q', '');

        if (strlen($query) < 2) {
            return new JsonResponse(['vehicles' => []]);
        }

        // Search for vehicles with Air Scales devices attached
        // Exclude vehicles user is already connected to
        $qb = $em->createQueryBuilder();
        $qb->select('v')
           ->from(Vehicle::class, 'v')
           ->leftJoin('v.devices', 'd')
           ->leftJoin('App\Entity\UserConnectedVehicle', 'ucv', 'WITH', 'ucv.vehicle = v AND ucv.user = :user AND ucv.isConnected = true')
           ->where('d.id IS NOT NULL') // Must have at least one device
           ->andWhere('ucv.id IS NULL') // User not already connected
           ->andWhere('v.created_by != :user OR v.created_by IS NULL') // User doesn't own it
           ->andWhere(
               $qb->expr()->orX(
                   $qb->expr()->like('LOWER(v.make)', ':query'),
                   $qb->expr()->like('LOWER(v.model)', ':query'),
                   $qb->expr()->like('LOWER(v.vin)', ':query'),
                   $qb->expr()->like('LOWER(v.license_plate)', ':query'),
                   $qb->expr()->like('LOWER(v.nickname)', ':query')
               )
           )
           ->setParameter('user', $user)
           ->setParameter('query', '%' . strtolower($query) . '%')
           ->setMaxResults(10);

        $vehicles = $qb->getQuery()->getResult();

        $vehicleData = [];
        foreach ($vehicles as $vehicle) {
            $vehicleData[] = [
                'id' => $vehicle->getId(),
                'name' => $vehicle->__toString(),
                'year' => $vehicle->getYear(),
                'make' => $vehicle->getMake(),
                'model' => $vehicle->getModel(),
                'nickname' => $vehicle->getNickname(),
                'vin' => $vehicle->getVin(),
                'license_plate' => $vehicle->getLicensePlate(),
                'device_count' => $vehicle->getDevices()->count()
            ];
        }

        return new JsonResponse([
            'vehicles' => $vehicleData
        ]);
    }

    #[Route('/connect/{id}', name: 'connect', methods: ['POST'])]
    public function connectToVehicle(
        int $id,
        EntityManagerInterface $em
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        $vehicle = $em->getRepository(Vehicle::class)->find($id);
        if (!$vehicle) {
            return new JsonResponse(['error' => 'Vehicle not found'], 404);
        }

        // Check if user already connected
        $existingConnection = $em->getRepository(\App\Entity\UserConnectedVehicle::class)->findOneBy([
            'user' => $user,
            'vehicle' => $vehicle
        ]);

        if ($existingConnection) {
            // Reactivate if disconnected
            if (!$existingConnection->getIsConnected()) {
                $existingConnection->setIsConnected(true);
                $existingConnection->setLastChangedAt(new \DateTimeImmutable());
                $em->flush();
            }

            return new JsonResponse([
                'success' => true,
                'message' => 'Connected to vehicle',
                'vehicle_id' => $vehicle->getId(),
                'vehicle_name' => $vehicle->__toString()
            ]);
        }

        // Create new connection
        $connection = new \App\Entity\UserConnectedVehicle();
        $connection->setUser($user);
        $connection->setVehicle($vehicle);
        $connection->setIsConnected(true);
        $connection->setLastChangedAt(new \DateTimeImmutable());

        $em->persist($connection);
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Connected to vehicle successfully',
            'vehicle_id' => $vehicle->getId(),
            'vehicle_name' => $vehicle->__toString()
        ]);
    }
}