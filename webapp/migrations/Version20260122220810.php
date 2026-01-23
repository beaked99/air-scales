<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260122220810 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE firmware (id INT AUTO_INCREMENT NOT NULL, version VARCHAR(32) NOT NULL, device_type VARCHAR(64) DEFAULT NULL, changelog LONGTEXT DEFAULT NULL, filename VARCHAR(255) NOT NULL, file_size INT NOT NULL, checksum VARCHAR(64) DEFAULT NULL, is_stable TINYINT(1) NOT NULL, is_deprecated TINYINT(1) NOT NULL, minimum_previous_version VARCHAR(32) DEFAULT NULL, download_count INT NOT NULL, released_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', created_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE user ADD auto_update_firmware TINYINT(1) DEFAULT 1 NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            DROP TABLE firmware
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE user DROP auto_update_firmware
        SQL);
    }
}
