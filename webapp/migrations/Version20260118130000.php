<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration: Add firmware_date column to device table
 *
 * Tracks the installation date/time of the firmware on the ESP32 device.
 * This helps with tracking firmware deployments and debugging device-specific issues.
 */
final class Version20260118130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add firmware_date column to device table for tracking firmware installation timestamp';
    }

    public function up(Schema $schema): void
    {
        // Add firmware_date column (datetime when firmware was installed on ESP32)
        $this->addSql(<<<'SQL'
            ALTER TABLE device ADD firmware_date DATETIME DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Remove firmware_date column
        $this->addSql(<<<'SQL'
            ALTER TABLE device DROP firmware_date
        SQL);
    }
}
