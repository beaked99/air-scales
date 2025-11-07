<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250626062301 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE device_access (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, device_id INT NOT NULL, first_seen_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', last_connected_at DATETIME NOT NULL, INDEX IDX_D5795A4FA76ED395 (user_id), INDEX IDX_D5795A4F94A4C7D4 (device_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE device_access ADD CONSTRAINT FK_D5795A4FA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE device_access ADD CONSTRAINT FK_D5795A4F94A4C7D4 FOREIGN KEY (device_id) REFERENCES device (id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE device_access DROP FOREIGN KEY FK_D5795A4FA76ED395
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE device_access DROP FOREIGN KEY FK_D5795A4F94A4C7D4
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE device_access
        SQL);
    }
}
