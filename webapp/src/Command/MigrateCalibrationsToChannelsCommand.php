<?php

namespace App\Command;

use App\Entity\Calibration;
use App\Entity\Device;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:migrate-calibrations-to-channels',
    description: 'Assign all existing calibrations without a device_channel to Channel 1',
)]
class MigrateCalibrationsToChannelsCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Migrating Calibrations to Channels');

        // Get all calibrations without a device channel assigned
        $calibrations = $this->em->getRepository(Calibration::class)
            ->createQueryBuilder('c')
            ->where('c.deviceChannel IS NULL')
            ->getQuery()
            ->getResult();

        $totalCalibrations = count($calibrations);

        if ($totalCalibrations === 0) {
            $io->success('No calibrations need migration - all are already assigned to channels!');
            return Command::SUCCESS;
        }

        $io->text("Found {$totalCalibrations} calibrations to migrate...");
        $io->newLine();

        $migratedCount = 0;
        $errorCount = 0;

        $io->progressStart($totalCalibrations);

        foreach ($calibrations as $calibration) {
            try {
                $device = $calibration->getDevice();

                // Get Channel 1 for this device
                $channel1 = $device->getChannel(1);

                if (!$channel1) {
                    $io->error("Device {$device->getId()} has no Channel 1! Run app:initialize-device-channels first.");
                    $errorCount++;
                    $io->progressAdvance();
                    continue;
                }

                // Assign calibration to Channel 1
                $calibration->setDeviceChannel($channel1);
                $migratedCount++;

            } catch (\Exception $e) {
                $io->error("Failed to migrate calibration {$calibration->getId()}: {$e->getMessage()}");
                $errorCount++;
            }

            $io->progressAdvance();
        }

        // Flush all changes at once
        $this->em->flush();

        $io->progressFinish();
        $io->newLine();

        // Display summary
        $io->success('Calibration migration complete!');
        $io->table(
            ['Status', 'Count'],
            [
                ['Total Calibrations', $totalCalibrations],
                ['Successfully Migrated to Channel 1', $migratedCount],
                ['Errors', $errorCount],
            ]
        );

        if ($migratedCount > 0) {
            $io->note('All existing calibrations have been assigned to Channel 1. New calibrations can now be assigned to either channel.');
        }

        return Command::SUCCESS;
    }
}
