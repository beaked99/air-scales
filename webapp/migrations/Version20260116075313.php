<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260116075313 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE device_channel (id INT AUTO_INCREMENT NOT NULL, device_id INT NOT NULL, axle_group_id INT DEFAULT NULL, channel_index INT NOT NULL, label_override VARCHAR(255) DEFAULT NULL, enabled TINYINT(1) NOT NULL, regression_intercept DOUBLE PRECISION DEFAULT NULL, regression_slope DOUBLE PRECISION DEFAULT NULL, regression_air_pressure_coeff DOUBLE PRECISION DEFAULT NULL, regression_ambient_pressure_coeff DOUBLE PRECISION DEFAULT NULL, regression_air_temp_coeff DOUBLE PRECISION DEFAULT NULL, regression_rsq DOUBLE PRECISION DEFAULT NULL, regression_rmse DOUBLE PRECISION DEFAULT NULL, sensor_type VARCHAR(64) DEFAULT NULL, INDEX IDX_284F57DA94A4C7D4 (device_id), INDEX IDX_284F57DAA5CAF111 (axle_group_id), UNIQUE INDEX device_channel_unique (device_id, channel_index), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE micro_data_channel (id INT AUTO_INCREMENT NOT NULL, micro_data_id INT NOT NULL, device_channel_id INT NOT NULL, air_pressure DOUBLE PRECISION DEFAULT NULL, weight DOUBLE PRECISION DEFAULT NULL, INDEX IDX_96EF8E2F6D9FDEFA (micro_data_id), INDEX IDX_96EF8E2FF5C6E26C (device_channel_id), UNIQUE INDEX micro_data_channel_unique (micro_data_id, device_channel_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE device_channel ADD CONSTRAINT FK_284F57DA94A4C7D4 FOREIGN KEY (device_id) REFERENCES device (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE device_channel ADD CONSTRAINT FK_284F57DAA5CAF111 FOREIGN KEY (axle_group_id) REFERENCES axle_group (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE micro_data_channel ADD CONSTRAINT FK_96EF8E2F6D9FDEFA FOREIGN KEY (micro_data_id) REFERENCES micro_data (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE micro_data_channel ADD CONSTRAINT FK_96EF8E2FF5C6E26C FOREIGN KEY (device_channel_id) REFERENCES device_channel (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE micro_data ADD gps_accuracy_m DOUBLE PRECISION DEFAULT NULL, CHANGE main_air_pressure main_air_pressure DOUBLE PRECISION DEFAULT NULL, CHANGE atmospheric_pressure atmospheric_pressure DOUBLE PRECISION DEFAULT NULL, CHANGE temperature temperature DOUBLE PRECISION DEFAULT NULL, CHANGE elevation elevation DOUBLE PRECISION DEFAULT NULL, CHANGE gps_lat gps_lat DOUBLE PRECISION DEFAULT NULL, CHANGE gps_lng gps_lng DOUBLE PRECISION DEFAULT NULL, CHANGE weight weight DOUBLE PRECISION DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX user_vehicle_unique ON user_vehicle_order (user_id, vehicle_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE device_channel DROP FOREIGN KEY FK_284F57DA94A4C7D4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE device_channel DROP FOREIGN KEY FK_284F57DAA5CAF111
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE micro_data_channel DROP FOREIGN KEY FK_96EF8E2F6D9FDEFA
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE micro_data_channel DROP FOREIGN KEY FK_96EF8E2FF5C6E26C
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE device_channel
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE micro_data_channel
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE micro_data DROP gps_accuracy_m, CHANGE atmospheric_pressure atmospheric_pressure DOUBLE PRECISION NOT NULL, CHANGE temperature temperature DOUBLE PRECISION NOT NULL, CHANGE elevation elevation DOUBLE PRECISION NOT NULL, CHANGE gps_lat gps_lat DOUBLE PRECISION NOT NULL, CHANGE gps_lng gps_lng DOUBLE PRECISION NOT NULL, CHANGE main_air_pressure main_air_pressure DOUBLE PRECISION NOT NULL, CHANGE weight weight DOUBLE PRECISION NOT NULL
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX user_vehicle_unique ON user_vehicle_order
        SQL);
    }
}
