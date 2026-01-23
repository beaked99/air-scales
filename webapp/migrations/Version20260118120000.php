<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration: Add espnow_rssi column to micro_data table
 *
 * ESP-NOW RSSI (Received Signal Strength Indicator) tracks the signal strength
 * between ESP32 devices in the mesh network, measured in dBm (e.g., -52 dBm).
 */
final class Version20260118120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add espnow_rssi column to micro_data table for ESP-NOW mesh signal strength tracking';
    }

    public function up(Schema $schema): void
    {
        // Add ESP-NOW RSSI column (signal strength in dBm)
        $this->addSql(<<<'SQL'
            ALTER TABLE micro_data ADD espnow_rssi INT DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Remove ESP-NOW RSSI column
        $this->addSql(<<<'SQL'
            ALTER TABLE micro_data DROP espnow_rssi
        SQL);
    }
}
