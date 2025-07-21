<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250718081237 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE device_role (id INT AUTO_INCREMENT NOT NULL, device_id INT NOT NULL, truck_configuration_id INT NOT NULL, role VARCHAR(50) NOT NULL, position VARCHAR(100) DEFAULT NULL, sort_order INT DEFAULT NULL, visual_position JSON DEFAULT NULL COMMENT '(DC2Type:json)', created_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_DE505F5094A4C7D4 (device_id), INDEX IDX_DE505F50F6F42740 (truck_configuration_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE truck_configuration (id INT AUTO_INCREMENT NOT NULL, owner_id INT NOT NULL, name VARCHAR(255) NOT NULL, layout JSON DEFAULT NULL COMMENT '(DC2Type:json)', last_used DATETIME DEFAULT NULL, created_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_4042FED97E3C61F9 (owner_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE device_role ADD CONSTRAINT FK_DE505F5094A4C7D4 FOREIGN KEY (device_id) REFERENCES device (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE device_role ADD CONSTRAINT FK_DE505F50F6F42740 FOREIGN KEY (truck_configuration_id) REFERENCES truck_configuration (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE truck_configuration ADD CONSTRAINT FK_4042FED97E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id)
        SQL);
        $this->addSql('ALTER TABLE device ADD `current_role` VARCHAR(20) DEFAULT NULL');
$this->addSql('ALTER TABLE device ADD `mesh_configuration` JSON DEFAULT NULL COMMENT \'(DC2Type:json)\'');
$this->addSql('ALTER TABLE device ADD `last_mesh_activity` DATETIME DEFAULT NULL');
$this->addSql('ALTER TABLE device ADD `signal_strength` INT DEFAULT NULL');
$this->addSql('ALTER TABLE device ADD `connected_slaves` JSON DEFAULT NULL COMMENT \'(DC2Type:json)\'');
$this->addSql('ALTER TABLE device ADD `master_device_mac` VARCHAR(17) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX user_device_unique ON device_access (user_id, device_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE device_role DROP FOREIGN KEY FK_DE505F5094A4C7D4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE device_role DROP FOREIGN KEY FK_DE505F50F6F42740
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE truck_configuration DROP FOREIGN KEY FK_4042FED97E3C61F9
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE device_role
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE truck_configuration
        SQL);
        $this->addSql('ALTER TABLE device DROP `current_role`, DROP `mesh_configuration`, DROP `last_mesh_activity`, DROP `signal_strength`, DROP `connected_slaves`, DROP `master_device_mac`');
        $this->addSql(<<<'SQL'
            DROP INDEX user_device_unique ON device_access
        SQL);
    }
}
