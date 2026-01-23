<?php

namespace App\Command;

use App\Entity\Device;
use App\Service\DeviceChannelService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:initialize-device-channels',
    description: 'Initialize Channel 1 and Channel 2 for all existing devices that do not have channels yet',
)]
class InitializeDeviceChannelsCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private DeviceChannelService $channelService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Initializing Device Channels');

        // Get all devices
        $devices = $this->em->getRepository(Device::class)->findAll();
        $totalDevices = count($devices);

        if ($totalDevices === 0) {
            $io->warning('No devices found in the database.');
            return Command::SUCCESS;
        }

        $io->text("Found {$totalDevices} devices to process...");
        $io->newLine();

        $initializedCount = 0;
        $skippedCount = 0;

        $io->progressStart($totalDevices);

        foreach ($devices as $device) {
            // Check if device already has channels
            if ($device->getDeviceChannels()->count() > 0) {
                $skippedCount++;
                $io->progressAdvance();
                continue;
            }

            // Initialize channels for this device
            try {
                $this->channelService->initializeDefaultChannels($device);
                $initializedCount++;
            } catch (\Exception $e) {
                $io->error("Failed to initialize channels for device {$device->getId()}: {$e->getMessage()}");
            }

            $io->progressAdvance();
        }

        $io->progressFinish();
        $io->newLine();

        // Display summary
        $io->success('Device channel initialization complete!');
        $io->table(
            ['Status', 'Count'],
            [
                ['Total Devices', $totalDevices],
                ['Initialized', $initializedCount],
                ['Already Had Channels (Skipped)', $skippedCount],
            ]
        );

        if ($initializedCount > 0) {
            $io->note('Each initialized device now has Channel 1 and Channel 2 ready for use.');
        }

        return Command::SUCCESS;
    }
}
