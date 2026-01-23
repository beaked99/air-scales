<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260116202222 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE calibration_session (id INT AUTO_INCREMENT NOT NULL, truck_configuration_id INT DEFAULT NULL, created_by_id INT NOT NULL, source VARCHAR(50) NOT NULL, ticket_number VARCHAR(100) DEFAULT NULL, occurred_at DATETIME NOT NULL, notes LONGTEXT DEFAULT NULL, location VARCHAR(255) DEFAULT NULL, created_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_53E66B31F6F42740 (truck_configuration_id), INDEX IDX_53E66B31B03A8386 (created_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE calibration_session ADD CONSTRAINT FK_53E66B31F6F42740 FOREIGN KEY (truck_configuration_id) REFERENCES truck_configuration (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE calibration_session ADD CONSTRAINT FK_53E66B31B03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE calibration ADD calibration_session_id INT DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE calibration ADD CONSTRAINT FK_FCC2B4138C88BFE5 FOREIGN KEY (calibration_session_id) REFERENCES calibration_session (id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_FCC2B4138C88BFE5 ON calibration (calibration_session_id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE truck_configuration ADD is_active TINYINT(1) NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE calibration DROP FOREIGN KEY FK_FCC2B4138C88BFE5
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE calibration_session DROP FOREIGN KEY FK_53E66B31F6F42740
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE calibration_session DROP FOREIGN KEY FK_53E66B31B03A8386
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE calibration_session
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX IDX_FCC2B4138C88BFE5 ON calibration
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE calibration DROP calibration_session_id
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE truck_configuration DROP is_active
        SQL);
    }
}
