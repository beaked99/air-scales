<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250624194636 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE calibration (id INT AUTO_INCREMENT NOT NULL, device_id INT NOT NULL, created_by_id INT NOT NULL, updated_by_id INT DEFAULT NULL, air_pressure DOUBLE PRECISION NOT NULL, ambient_air_pressure DOUBLE PRECISION NOT NULL, air_temperature DOUBLE PRECISION NOT NULL, elevation DOUBLE PRECISION NOT NULL, scale_weight DOUBLE PRECISION NOT NULL, comment LONGTEXT DEFAULT NULL, created_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_FCC2B41394A4C7D4 (device_id), INDEX IDX_FCC2B413B03A8386 (created_by_id), INDEX IDX_FCC2B413896DBBDE (updated_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE device (id INT AUTO_INCREMENT NOT NULL, vehicle_id INT NOT NULL, sold_to_id INT DEFAULT NULL, serial_number VARCHAR(64) DEFAULT NULL, mac_address VARCHAR(17) DEFAULT NULL, device_type VARCHAR(64) DEFAULT NULL, firmware_version VARCHAR(64) DEFAULT NULL, order_date DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', ship_date DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', tracking_id VARCHAR(64) DEFAULT NULL, notes VARCHAR(255) DEFAULT NULL, created_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_92FB68E545317D1 (vehicle_id), INDEX IDX_92FB68E8E5CC2C0 (sold_to_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE vehicle (id INT AUTO_INCREMENT NOT NULL, created_by_id INT DEFAULT NULL, updated_by_id INT DEFAULT NULL, year INT NOT NULL, make VARCHAR(64) DEFAULT NULL, model VARCHAR(64) DEFAULT NULL, nickname VARCHAR(64) DEFAULT NULL, vin VARCHAR(17) DEFAULT NULL, license_plate VARCHAR(20) DEFAULT NULL, last_seen DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', created_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_1B80E486B03A8386 (created_by_id), INDEX IDX_1B80E486896DBBDE (updated_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE calibration ADD CONSTRAINT FK_FCC2B41394A4C7D4 FOREIGN KEY (device_id) REFERENCES device (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE calibration ADD CONSTRAINT FK_FCC2B413B03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE calibration ADD CONSTRAINT FK_FCC2B413896DBBDE FOREIGN KEY (updated_by_id) REFERENCES user (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE device ADD CONSTRAINT FK_92FB68E545317D1 FOREIGN KEY (vehicle_id) REFERENCES vehicle (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE device ADD CONSTRAINT FK_92FB68E8E5CC2C0 FOREIGN KEY (sold_to_id) REFERENCES user (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE vehicle ADD CONSTRAINT FK_1B80E486B03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE vehicle ADD CONSTRAINT FK_1B80E486896DBBDE FOREIGN KEY (updated_by_id) REFERENCES user (id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE calibration DROP FOREIGN KEY FK_FCC2B41394A4C7D4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE calibration DROP FOREIGN KEY FK_FCC2B413B03A8386
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE calibration DROP FOREIGN KEY FK_FCC2B413896DBBDE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE device DROP FOREIGN KEY FK_92FB68E545317D1
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE device DROP FOREIGN KEY FK_92FB68E8E5CC2C0
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE vehicle DROP FOREIGN KEY FK_1B80E486B03A8386
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE vehicle DROP FOREIGN KEY FK_1B80E486896DBBDE
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE calibration
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE device
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE vehicle
        SQL);
    }
}
