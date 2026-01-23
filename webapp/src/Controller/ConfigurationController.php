<?php

namespace App\Controller;

use App\Entity\TruckConfiguration;
use App\Entity\DeviceRole;
use App\Entity\Device;
use App\Entity\AxleGroup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/configuration')]
#[IsGranted('ROLE_USER')]
class ConfigurationController extends AbstractController
{
    #[Route('/', name: 'configuration_index')]
    public function index(EntityManagerInterface $em): Response
    {
        $user = $this->getUser();

        // Get configurations owned by this user OR shared configurations
        $configurations = $em->getRepository(TruckConfiguration::class)
            ->createQueryBuilder('tc')
            ->where('tc.owner = :user')
            ->orWhere('tc.isShared = :true')
            ->setParameter('user', $user)
            ->setParameter('true', true)
            ->orderBy('tc.sortOrder', 'ASC')
            ->addOrderBy('tc.id', 'ASC')
            ->getQuery()
            ->getResult();

        // Get attention needed items
        $attentionNeeded = $this->getAttentionNeededItems($em, $user, $configurations);

        return $this->render('configuration/index.html.twig', [
            'configurations' => $configurations,
            'attentionNeeded' => $attentionNeeded,
        ]);
    }

    #[Route('/reorder', name: 'configuration_reorder', methods: ['POST'])]
    public function reorder(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);
        $order = $data['order'] ?? [];

        if (empty($order)) {
            return new JsonResponse(['error' => 'No order data provided'], 400);
        }

        foreach ($order as $item) {
            $configId = $item['id'] ?? null;
            $sortOrder = $item['sortOrder'] ?? null;

            if ($configId !== null && $sortOrder !== null) {
                $configuration = $em->getRepository(TruckConfiguration::class)->find($configId);

                // Only allow reordering user's own configurations
                if ($configuration && $configuration->getOwner() === $user) {
                    $configuration->setSortOrder($sortOrder);
                }
            }
        }

        $em->flush();

        return new JsonResponse(['success' => true, 'message' => 'Configuration order saved']);
    }

    #[Route('/quick-add', name: 'configuration_quick_add', methods: ['POST'])]
    public function quickAdd(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);
        $name = trim($data['name'] ?? '');

        if (!$name) {
            return new JsonResponse(['error' => 'Configuration name is required'], 400);
        }

        $configuration = new TruckConfiguration();
        $configuration->setName($name);
        $configuration->setOwner($user);
        $configuration->setIsActive(false);

        $em->persist($configuration);
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'id' => $configuration->getId(),
            'name' => $configuration->getName(),
        ]);
    }

    #[Route('/new', name: 'configuration_new')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');

            if (!$name) {
                $this->addFlash('error', 'Configuration name is required.');
                return $this->redirectToRoute('configuration_new');
            }

            $configuration = new TruckConfiguration();
            $configuration->setName($name);
            $configuration->setOwner($user);
            $configuration->setIsActive(false);

            $em->persist($configuration);
            $em->flush();

            $this->addFlash('success', 'Configuration created successfully!');
            return $this->redirectToRoute('configuration_edit', ['id' => $configuration->getId()]);
        }

        // Get available devices for this user
        $devices = $this->getUserDevices($em, $user);

        return $this->render('configuration/new.html.twig', [
            'devices' => $devices,
        ]);
    }

    #[Route('/{id}', name: 'configuration_show', requirements: ['id' => '\d+'])]
    public function show(int $id, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        $configuration = $em->getRepository(TruckConfiguration::class)->find($id);

        if (!$configuration) {
            throw $this->createNotFoundException('Configuration not found');
        }

        // Check if user has access (owner OR shared config)
        if ($configuration->getOwner() !== $user && !$configuration->isShared()) {
            throw $this->createAccessDeniedException('You do not have access to this configuration');
        }

        // Get axle groups with their status
        $axleGroups = [];
        foreach ($configuration->getAxleGroups() as $axleGroup) {
            $axleGroups[] = [
                'entity' => $axleGroup,
                'status' => $axleGroup->getCalibrationStatus(),
                'points' => $axleGroup->getMinCalibrationPoints(),
            ];
        }

        return $this->render('configuration/show.html.twig', [
            'configuration' => $configuration,
            'axleGroups' => $axleGroups,
        ]);
    }

    #[Route('/{id}/edit', name: 'configuration_edit')]
    public function edit(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        $configuration = $em->getRepository(TruckConfiguration::class)->find($id);

        if (!$configuration || $configuration->getOwner() !== $user) {
            throw $this->createNotFoundException('Configuration not found');
        }

        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');

            if ($name) {
                $configuration->setName($name);
                $em->flush();
                $this->addFlash('success', 'Configuration updated successfully!');
            }
        }

        $devices = $this->getUserDevices($em, $user);
        $axleGroups = $em->getRepository(AxleGroup::class)->findAll();

        return $this->render('configuration/edit.html.twig', [
            'configuration' => $configuration,
            'devices' => $devices,
            'axleGroups' => $axleGroups,
        ]);
    }

    #[Route('/{id}/activate', name: 'configuration_activate', methods: ['POST'])]
    public function activate(int $id, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $configuration = $em->getRepository(TruckConfiguration::class)->find($id);

        if (!$configuration) {
            return new JsonResponse(['error' => 'Configuration not found'], 404);
        }

        // Check if user has access (owner OR shared config)
        if ($configuration->getOwner() !== $user && !$configuration->isShared()) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }

        // Deactivate all other configurations for this user
        $em->getRepository(TruckConfiguration::class)
            ->createQueryBuilder('tc')
            ->update()
            ->set('tc.isActive', ':false')
            ->where('tc.owner = :user')
            ->setParameter('false', false)
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();

        // Activate this configuration
        $configuration->setIsActive(true);
        $configuration->setLastUsed(new \DateTime());
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Configuration activated',
        ]);
    }

    #[Route('/{id}/delete', name: 'configuration_delete', methods: ['POST'])]
    public function delete(int $id, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $configuration = $em->getRepository(TruckConfiguration::class)->find($id);

        if (!$configuration || $configuration->getOwner() !== $user) {
            return new JsonResponse(['error' => 'Configuration not found'], 404);
        }

        $em->remove($configuration);
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Configuration deleted',
        ]);
    }

    #[Route('/{id}/toggle-shared', name: 'configuration_toggle_shared', methods: ['POST'])]
    public function toggleShared(int $id, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $configuration = $em->getRepository(TruckConfiguration::class)->find($id);

        if (!$configuration || $configuration->getOwner() !== $user) {
            return new JsonResponse(['error' => 'Configuration not found or access denied'], 404);
        }

        // Toggle the shared status
        $configuration->setIsShared(!$configuration->isShared());
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'isShared' => $configuration->isShared(),
            'message' => $configuration->isShared()
                ? 'Configuration is now shared with all users'
                : 'Configuration is now private',
        ]);
    }

    #[Route('/{id}/toggle-dashboard', name: 'configuration_toggle_dashboard', methods: ['POST'])]
    public function toggleDashboard(int $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $configuration = $em->getRepository(TruckConfiguration::class)->find($id);

        if (!$configuration) {
            return new JsonResponse(['error' => 'Configuration not found'], 404);
        }

        // Check if user has access (owner OR shared config)
        if ($configuration->getOwner() !== $user && !$configuration->isShared()) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }

        $data = json_decode($request->getContent(), true);
        $isActive = $data['isActive'] ?? false;

        // Simply toggle this configuration - allow multiple active at once
        $configuration->setIsActive($isActive);
        if ($isActive) {
            $configuration->setLastUsed(new \DateTime());
        }

        $em->flush();

        return new JsonResponse([
            'success' => true,
            'isActive' => $configuration->isActive(),
            'message' => $configuration->isActive()
                ? 'Configuration is now shown on dashboard'
                : 'Configuration removed from dashboard',
        ]);
    }

    #[Route('/{id}/add-device', name: 'configuration_add_device', methods: ['POST'])]
    public function addDevice(int $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $configuration = $em->getRepository(TruckConfiguration::class)->find($id);

        if (!$configuration || $configuration->getOwner() !== $user) {
            return new JsonResponse(['error' => 'Configuration not found'], 404);
        }

        $deviceId = $request->request->get('device_id');
        $role = $request->request->get('role', 'device');

        $device = $em->getRepository(Device::class)->find($deviceId);
        if (!$device) {
            return new JsonResponse(['error' => 'Device not found'], 404);
        }

        // Check if device already in this configuration
        foreach ($configuration->getDeviceRoles() as $existingRole) {
            if ($existingRole->getDevice() === $device) {
                return new JsonResponse(['error' => 'Device already in configuration'], 400);
            }
        }

        $deviceRole = new DeviceRole();
        $deviceRole->setDevice($device);
        $deviceRole->setTruckConfiguration($configuration);
        $deviceRole->setRole($role);
        $deviceRole->setSortOrder($configuration->getDeviceRoles()->count());

        $em->persist($deviceRole);
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Device added to configuration',
        ]);
    }

    #[Route('/{configId}/remove-device/{deviceRoleId}', name: 'configuration_remove_device', methods: ['POST'])]
    public function removeDevice(int $configId, int $deviceRoleId, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $configuration = $em->getRepository(TruckConfiguration::class)->find($configId);

        if (!$configuration || $configuration->getOwner() !== $user) {
            return new JsonResponse(['error' => 'Configuration not found'], 404);
        }

        $deviceRole = $em->getRepository(DeviceRole::class)->find($deviceRoleId);
        if (!$deviceRole || $deviceRole->getTruckConfiguration() !== $configuration) {
            return new JsonResponse(['error' => 'Device role not found'], 404);
        }

        $em->remove($deviceRole);
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Device removed from configuration',
        ]);
    }

    #[Route('/{id}/bulk-calibration', name: 'configuration_bulk_calibration')]
    public function bulkCalibration(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        $configuration = $em->getRepository(TruckConfiguration::class)->find($id);

        if (!$configuration) {
            throw $this->createNotFoundException('Configuration not found');
        }

        // Check if user has access (owner OR shared config)
        if ($configuration->getOwner() !== $user && !$configuration->isShared()) {
            throw $this->createAccessDeniedException('You do not have access to this configuration');
        }

        // Build list of devices with their channels in display order
        $deviceList = [];

        foreach ($configuration->getDeviceRoles() as $deviceRole) {
            $device = $deviceRole->getDevice();

            if (!$device) continue;

            // Build combined sorted array of channels + virtual steer for this device
            $allChannelItems = [];

            // Add virtual steer if enabled
            if ($device->hasVirtualSteer()) {
                $allChannelItems[] = [
                    'type' => 'virtual-steer',
                    'order' => $device->getVirtualSteerDisplayOrder() ?? 0,
                    'device' => $device,
                ];
            }

            // Add regular channels
            foreach ($device->getDeviceChannels() as $channel) {
                if ($channel->isEnabled()) {
                    $allChannelItems[] = [
                        'type' => 'channel',
                        'order' => $channel->getDisplayOrder() ?? ($channel->getChannelIndex() + 100),
                        'channel' => $channel,
                    ];
                }
            }

            // Sort by display order
            usort($allChannelItems, function($a, $b) {
                return $a['order'] <=> $b['order'];
            });

            $deviceList[] = [
                'device' => $device,
                'role' => $deviceRole->getRole(),
                'items' => $allChannelItems,
            ];
        }

        $axleGroups = $deviceList;

        if ($request->isMethod('POST')) {
            return $this->processBulkCalibration($configuration, $request, $em);
        }

        return $this->render('configuration/bulk_calibration.html.twig', [
            'configuration' => $configuration,
            'axleGroups' => $axleGroups,
        ]);
    }

    private function processBulkCalibration(TruckConfiguration $configuration, Request $request, EntityManagerInterface $em): Response
    {
        $ticketNumber = $request->request->get('ticket_number');
        $location = $request->request->get('location');
        $notes = $request->request->get('notes');
        $occurredAt = $request->request->get('occurred_at');
        $channelWeights = $request->request->all('channel_weights'); // Array of channelId => weight
        $virtualSteerWeight = $request->request->get('virtual_steer_weight'); // Virtual steer weight

        // Debug logging
        error_log('🔍 DEBUG channelWeights: ' . json_encode($channelWeights));
        error_log('🔍 DEBUG virtualSteerWeight: ' . $virtualSteerWeight);
        error_log('🔍 DEBUG all POST data: ' . json_encode($request->request->all()));

        // Create calibration session
        $session = new \App\Entity\CalibrationSession();
        $session->setTruckConfiguration($configuration);
        $session->setCreatedBy($this->getUser());
        $session->setSource('TRUCK_SCALE');
        $session->setTicketNumber($ticketNumber);
        $session->setLocation($location);

        // Store virtual steer weight in notes if provided
        $sessionNotes = $notes ?: '';
        if (!empty($virtualSteerWeight) && $virtualSteerWeight > 0) {
            $sessionNotes .= ($sessionNotes ? "\n" : '') . "[VIRTUAL_STEER_WEIGHT:{$virtualSteerWeight}]";
        }
        $session->setNotes($sessionNotes);

        if ($occurredAt) {
            try {
                $session->setOccurredAt(new \DateTime($occurredAt));
            } catch (\Exception $e) {
                $session->setOccurredAt(new \DateTime());
            }
        }

        $em->persist($session);

        $calibrationsAdded = 0;
        $errors = [];

        // Process each channel weight individually
        foreach ($channelWeights as $channelId => $weight) {
            if (empty($weight) || $weight <= 0) {
                continue;
            }

            // Find the channel
            $channel = $em->getRepository(\App\Entity\DeviceChannel::class)->find($channelId);
            if (!$channel) {
                $errors[] = "Channel not found (ID: {$channelId})";
                continue;
            }

            $device = $channel->getDevice();

            // Get latest micro data for this device
            $latestData = $em->getRepository(\App\Entity\MicroData::class)
                ->createQueryBuilder('m')
                ->where('m.device = :device')
                ->setParameter('device', $device)
                ->orderBy('m.id', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if (!$latestData) {
                $errors[] = "No sensor data available for " . $channel->getDisplayLabel();
                continue;
            }

            // Create calibration point
            $calibration = new \App\Entity\Calibration();
            $calibration->setDevice($device);
            $calibration->setDeviceChannel($channel);
            $calibration->setCreatedBy($this->getUser());
            $calibration->setCalibrationSession($session);
            $calibration->setScaleWeight($weight);

            // Use channel-specific data if available
            $channelAirPressure = null;
            foreach ($latestData->getMicroDataChannels() as $mdc) {
                $mdcChannel = $mdc->getDeviceChannel();
                if ($mdcChannel && $mdcChannel->getChannelIndex() === $channel->getChannelIndex()) {
                    $channelAirPressure = $mdc->getAirPressure();
                    break;
                }
            }

            // Use channel-specific pressure if found, otherwise fallback to main air pressure
            if ($channelAirPressure !== null) {
                $calibration->setAirPressure($channelAirPressure);
            } elseif ($latestData->getMainAirPressure() !== null) {
                $calibration->setAirPressure($latestData->getMainAirPressure());
            } else {
                $errors[] = "No air pressure data available for " . $channel->getDisplayLabel();
                continue;
            }

            // Set ambient air pressure, temperature, and elevation with null checks
            if ($latestData->getAtmosphericPressure() !== null) {
                $calibration->setAmbientAirPressure($latestData->getAtmosphericPressure());
            } else {
                $errors[] = "No atmospheric pressure data available for " . $channel->getDisplayLabel();
                continue;
            }

            if ($latestData->getTemperature() !== null) {
                $calibration->setAirTemperature($latestData->getTemperature());
            } else {
                $errors[] = "No temperature data available for " . $channel->getDisplayLabel();
                continue;
            }

            if ($latestData->getElevation() !== null) {
                $calibration->setElevation($latestData->getElevation());
            } else {
                $errors[] = "No elevation data available for " . $channel->getDisplayLabel();
                continue;
            }

            $calibration->setComment("Truck scale: " . ($location ?: 'Unknown location'));

            $em->persist($calibration);
            $session->addCalibration($calibration);
            $calibrationsAdded++;
        }

        if ($calibrationsAdded > 0) {
            $em->flush();

            // Run regression for affected devices
            $regressor = new \App\Service\DeviceCalibrationRegressor($em);
            $virtualSteerCalculator = new \App\Service\VirtualSteerCalculator($em);

            foreach ($configuration->getDeviceRoles() as $deviceRole) {
                $device = $deviceRole->getDevice();
                if ($device) {
                    $regressor->run($device);

                    // Train virtual steer regression if enabled
                    if ($device->hasVirtualSteer()) {
                        $virtualSteerCalculator->learnFromCalibrations($device);
                    }
                }
            }

            $message = "Added {$calibrationsAdded} calibration point(s)";
            if (count($errors) > 0) {
                $message .= ", but encountered some errors.";
            }

            $this->addFlash('success', $message);

            if (count($errors) > 0) {
                foreach ($errors as $error) {
                    $this->addFlash('warning', $error);
                }
            }

            return $this->redirectToRoute('configuration_show', ['id' => $configuration->getId()]);
        } else {
            $this->addFlash('error', 'No calibration points were added. Please enter weights and ensure devices have recent sensor data.');
            if (count($errors) > 0) {
                foreach ($errors as $error) {
                    $this->addFlash('error', $error);
                }
            }
            return $this->redirectToRoute('configuration_bulk_calibration', ['id' => $configuration->getId()]);
        }
    }

    /**
     * Get all devices owned by or accessible to the user
     */
    private function getUserDevices(EntityManagerInterface $em, $user): array
    {
        // Devices user purchased
        $purchasedDevices = $em->getRepository(Device::class)->findBy(['soldTo' => $user]);
        $devices = [];
        foreach ($purchasedDevices as $device) {
            $devices[$device->getId()] = $device;
        }

        // Devices user has access to
        $accessRecords = $em->getRepository(\App\Entity\DeviceAccess::class)->findBy([
            'user' => $user,
            'isActive' => true
        ]);
        foreach ($accessRecords as $access) {
            $devices[$access->getDevice()->getId()] = $access->getDevice();
        }

        return array_values($devices);
    }

    /**
     * Get items that need attention
     */
    private function getAttentionNeededItems(EntityManagerInterface $em, $user, array $configurations): array
    {
        $items = [];

        foreach ($configurations as $config) {
            // Check each axle group for calibration issues
            foreach ($config->getAxleGroups() as $axleGroup) {
                $status = $axleGroup->getCalibrationStatus();
                $points = $axleGroup->getMinCalibrationPoints();

                if ($status === 'critical') {
                    $items[] = [
                        'type' => 'critical',
                        'message' => $axleGroup->getLabel() . ' needs calibration (0 points)',
                        'config' => $config,
                        'axleGroup' => $axleGroup,
                    ];
                } elseif ($status === 'warning') {
                    $items[] = [
                        'type' => 'warning',
                        'message' => $axleGroup->getLabel() . ' needs more calibration (' . $points . ' points)',
                        'config' => $config,
                        'axleGroup' => $axleGroup,
                    ];
                }
            }

            // Check for offline devices
            foreach ($config->getDeviceRoles() as $deviceRole) {
                $device = $deviceRole->getDevice();
                if ($device) {
                    $lastData = $em->getRepository(\App\Entity\MicroData::class)
                        ->createQueryBuilder('m')
                        ->where('m.device = :device')
                        ->setParameter('device', $device)
                        ->orderBy('m.id', 'DESC')
                        ->setMaxResults(1)
                        ->getQuery()
                        ->getOneOrNullResult();

                    if ($lastData) {
                        $now = new \DateTime();
                        $diff = $now->getTimestamp() - $lastData->getTimestamp()->getTimestamp();

                        if ($diff > 21600) { // 6 hours
                            $hours = floor($diff / 3600);
                            $items[] = [
                                'type' => 'warning',
                                'message' => $device->getSerialNumber() . ' offline (' . $hours . 'h ago)',
                                'config' => $config,
                                'device' => $device,
                            ];
                        }
                    }
                }
            }
        }

        return $items;
    }

    #[Route('/{id}/reorder-devices', name: 'configuration_reorder_devices', methods: ['POST'])]
    public function reorderDevices(int $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $configuration = $em->getRepository(TruckConfiguration::class)->find($id);

        if (!$configuration || $configuration->getOwner() !== $user) {
            return new JsonResponse(['error' => 'Configuration not found or access denied'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $order = $data['order'] ?? [];

        if (empty($order)) {
            return new JsonResponse(['error' => 'No order data provided'], 400);
        }

        // Update sort order for each device role
        foreach ($order as $item) {
            $deviceRoleId = $item['id'] ?? null;
            $sortOrder = $item['sortOrder'] ?? null;

            if ($deviceRoleId !== null && $sortOrder !== null) {
                $deviceRole = $em->getRepository(DeviceRole::class)->find($deviceRoleId);

                if ($deviceRole && $deviceRole->getTruckConfiguration() === $configuration) {
                    $deviceRole->setSortOrder($sortOrder);
                }
            }
        }

        $em->flush();

        return new JsonResponse(['success' => true, 'message' => 'Device order saved']);
    }
}
