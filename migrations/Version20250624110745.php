<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250624110745 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE calibration DROP FOREIGN KEY FK_FCC2B413AF1B6DB9
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE calibration DROP FOREIGN KEY FK_FCC2B413896DBBDE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE calibration DROP FOREIGN KEY FK_FCC2B413B03A8386
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX IDX_FCC2B413AF1B6DB9 ON calibration
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX IDX_FCC2B413B03A8386 ON calibration
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX IDX_FCC2B413896DBBDE ON calibration
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE calibration ADD temperature DOUBLE PRECISION NOT NULL, ADD axle_weight DOUBLE PRECISION NOT NULL, DROP calibrated_by_id, DROP created_by_id, DROP updated_by_id, DROP known_weight, DROP raw_sensor_value, DROP ambient_temperature, DROP ambient_air_pressure, DROP axle_position, DROP vehicle_type, DROP notes, DROP is_active, DROP calibrated_at, DROP created_at, DROP updated_at, DROP calculated_coefficient, DROP linear_offset, DROP accuracy_percentage, CHANGE air_pressure air_pressure DOUBLE PRECISION NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE calibration ADD calibrated_by_id INT DEFAULT NULL, ADD created_by_id INT DEFAULT NULL, ADD updated_by_id INT DEFAULT NULL, ADD known_weight NUMERIC(10, 2) NOT NULL, ADD raw_sensor_value NUMERIC(10, 4) NOT NULL, ADD ambient_temperature NUMERIC(5, 2) NOT NULL, ADD ambient_air_pressure NUMERIC(8, 2) NOT NULL, ADD axle_position VARCHAR(50) DEFAULT NULL, ADD vehicle_type VARCHAR(20) DEFAULT NULL, ADD notes VARCHAR(255) DEFAULT NULL, ADD is_active TINYINT(1) DEFAULT 1 NOT NULL, ADD calibrated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', ADD created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', ADD updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', ADD calculated_coefficient NUMERIC(10, 6) DEFAULT NULL, ADD linear_offset NUMERIC(10, 6) DEFAULT NULL, ADD accuracy_percentage NUMERIC(8, 4) DEFAULT NULL, DROP temperature, DROP axle_weight, CHANGE air_pressure air_pressure NUMERIC(8, 2) NOT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE calibration ADD CONSTRAINT FK_FCC2B413AF1B6DB9 FOREIGN KEY (calibrated_by_id) REFERENCES user (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE calibration ADD CONSTRAINT FK_FCC2B413896DBBDE FOREIGN KEY (updated_by_id) REFERENCES user (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE calibration ADD CONSTRAINT FK_FCC2B413B03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_FCC2B413AF1B6DB9 ON calibration (calibrated_by_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_FCC2B413B03A8386 ON calibration (created_by_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_FCC2B413896DBBDE ON calibration (updated_by_id)
        SQL);
    }
}
