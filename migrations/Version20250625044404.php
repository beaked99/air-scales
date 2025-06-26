<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250625044404 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE micro_data (id INT AUTO_INCREMENT NOT NULL, device_id INT NOT NULL, main_air_pressure DOUBLE PRECISION NOT NULL, atmospheric_pressure DOUBLE PRECISION NOT NULL, temperature DOUBLE PRECISION NOT NULL, elevation DOUBLE PRECISION NOT NULL, gps_lat DOUBLE PRECISION NOT NULL, gps_lng DOUBLE PRECISION NOT NULL, mac_id VARCHAR(255) NOT NULL, timestamp DATETIME NOT NULL, weight DOUBLE PRECISION NOT NULL, INDEX IDX_DD967FF994A4C7D4 (device_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE micro_data ADD CONSTRAINT FK_DD967FF994A4C7D4 FOREIGN KEY (device_id) REFERENCES device (id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE micro_data DROP FOREIGN KEY FK_DD967FF994A4C7D4
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE micro_data
        SQL);
    }
}
