<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260115045531 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE device ADD first_activated_by_id INT DEFAULT NULL, ADD first_activated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', ADD subscription_granted TINYINT(1) NOT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE device ADD CONSTRAINT FK_92FB68E4AEEA7C5 FOREIGN KEY (first_activated_by_id) REFERENCES user (id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_92FB68E4AEEA7C5 ON device (first_activated_by_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE device DROP FOREIGN KEY FK_92FB68E4AEEA7C5
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX IDX_92FB68E4AEEA7C5 ON device
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE device DROP first_activated_by_id, DROP first_activated_at, DROP subscription_granted
        SQL);
    }
}
