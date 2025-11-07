<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250628064528 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE user_connected_vehicle (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, vehicle_id INT NOT NULL, is_connected TINYINT(1) NOT NULL, last_changed_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_706F1854A76ED395 (user_id), INDEX IDX_706F1854545317D1 (vehicle_id), UNIQUE INDEX user_vehicle_unique (user_id, vehicle_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE user_connected_vehicle ADD CONSTRAINT FK_706F1854A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE user_connected_vehicle ADD CONSTRAINT FK_706F1854545317D1 FOREIGN KEY (vehicle_id) REFERENCES vehicle (id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE user_connected_vehicle DROP FOREIGN KEY FK_706F1854A76ED395
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE user_connected_vehicle DROP FOREIGN KEY FK_706F1854545317D1
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE user_connected_vehicle
        SQL);
    }
}
