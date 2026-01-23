<?php

namespace App\Service;

use App\Entity\Device;
use App\Entity\DeviceChannel;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class DeviceChannelService
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Initialize default channels for a device (Channel 1 and Channel 2)
     * This should be called when a device is first registered
     *
     * IMPORTANT: channel_index (1, 2) MUST match the ESP32 firmware's channel numbering.
     * Virtual steer is NOT a physical channel - it's a calculated UI element with displayOrder=0.
     *
     * ESP32 sends data as ch1_weight, ch2_weight which maps to channel_index 1 and 2.
     * DO NOT create a "channel 0" for virtual steer - virtual steer uses Device.virtualSteerDisplayOrder.
     */
    public function initializeDefaultChannels(Device $device): void
    {
        // Check if channels already exist
        if ($device->getDeviceChannels()->count() > 0) {
            $this->logger->info('Device already has channels, skipping initialization', [
                'device_id' => $device->getId(),
                'mac_address' => $device->getMacAddress()
            ]);
            return;
        }

        $this->logger->info('Initializing default channels for device', [
            'device_id' => $device->getId(),
            'mac_address' => $device->getMacAddress()
        ]);

        // Create Channel 1
        $channel1 = new DeviceChannel();
        $channel1->setDevice($device);
        $channel1->setChannelIndex(1);
        $channel1->setEnabled(true);
        $channel1->setLabelOverride('Channel 1'); // Default label
        $channel1->setDisplayOrder(1); // Set display order for drag-and-drop

        $this->em->persist($channel1);
        $device->addDeviceChannel($channel1);

        // Create Channel 2 (disabled by default - user must enable dual channel mode)
        $channel2 = new DeviceChannel();
        $channel2->setDevice($device);
        $channel2->setChannelIndex(2);
        $channel2->setEnabled(false); // Disabled by default
        $channel2->setLabelOverride('Channel 2'); // Default label
        $channel2->setDisplayOrder(2); // Set display order for drag-and-drop

        $this->em->persist($channel2);
        $device->addDeviceChannel($channel2);

        $this->em->flush();

        $this->logger->info('Default channels created successfully', [
            'device_id' => $device->getId(),
            'channel_1_id' => $channel1->getId(),
            'channel_2_id' => $channel2->getId()
        ]);
    }

    /**
     * Migrate old single-channel regression coefficients to Channel 1
     * This should be called during data migration
     */
    public function migrateOldCalibrationToChannel1(Device $device): bool
    {
        // Check if device has old-style calibration data
        if ($device->getRegressionIntercept() === null &&
            $device->getRegressionAirPressureCoeff() === null) {
            return false; // No old calibration to migrate
        }

        $channel1 = $device->getChannel(1);
        if (!$channel1) {
            $this->logger->warning('Cannot migrate calibration: Channel 1 does not exist', [
                'device_id' => $device->getId()
            ]);
            return false;
        }

        // Copy old calibration to Channel 1
        $channel1->setRegressionIntercept($device->getRegressionIntercept());
        $channel1->setRegressionAirPressureCoeff($device->getRegressionAirPressureCoeff());
        $channel1->setRegressionAmbientPressureCoeff($device->getRegressionAmbientPressureCoeff());
        $channel1->setRegressionAirTempCoeff($device->getRegressionAirTempCoeff());
        $channel1->setRegressionRsq($device->getRegressionRsq());
        $channel1->setRegressionRmse($device->getRegressionRmse());

        $this->em->flush();

        $this->logger->info('Migrated old calibration data to Channel 1', [
            'device_id' => $device->getId(),
            'channel_id' => $channel1->getId()
        ]);

        return true;
    }

    /**
     * Get calibration coefficients for a specific channel
     * Returns array or null if no calibration exists
     */
    public function getChannelCalibration(DeviceChannel $channel): ?array
    {
        if ($channel->getRegressionIntercept() === null &&
            $channel->getRegressionAirPressureCoeff() === null) {
            return null;
        }

        return [
            'channel_index' => $channel->getChannelIndex(),
            'intercept' => $channel->getRegressionIntercept() ?? 0.0,
            'slope' => $channel->getRegressionSlope() ?? 0.0,
            'air_pressure_coeff' => $channel->getRegressionAirPressureCoeff() ?? 0.0,
            'ambient_pressure_coeff' => $channel->getRegressionAmbientPressureCoeff() ?? 0.0,
            'air_temp_coeff' => $channel->getRegressionAirTempCoeff() ?? 0.0,
            'r_squared' => $channel->getRegressionRsq() ?? 0.0,
            'rmse' => $channel->getRegressionRmse() ?? 0.0
        ];
    }

    /**
     * Get all calibration data for a device (both channels)
     * Returns associative array keyed by channel_index (1, 2, etc.)
     * IMPORTANT: Keys are channel indices, NOT array indices!
     */
    public function getAllChannelCalibrations(Device $device): array
    {
        $calibrations = [];

        foreach ($device->getDeviceChannels() as $channel) {
            $calibration = $this->getChannelCalibration($channel);
            if ($calibration !== null) {
                // Use channel_index as the key (1, 2, etc.)
                $calibrations[$channel->getChannelIndex()] = $calibration;
            }
        }

        return $calibrations;
    }
}
