<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250627045600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE axle_group (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, label VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE vehicle ADD axle_group_id INT DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE vehicle ADD CONSTRAINT FK_1B80E486A5CAF111 FOREIGN KEY (axle_group_id) REFERENCES axle_group (id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_1B80E486A5CAF111 ON vehicle (axle_group_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE vehicle DROP FOREIGN KEY FK_1B80E486A5CAF111
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE axle_group
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX IDX_1B80E486A5CAF111 ON vehicle
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE vehicle DROP axle_group_id
        SQL);
    }
}
