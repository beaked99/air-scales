<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250611015808 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE vehicle ADD created_by_id INT DEFAULT NULL, ADD updated_by_id INT DEFAULT NULL, ADD vin VARCHAR(17) DEFAULT NULL, ADD license_plate VARCHAR(20) DEFAULT NULL, ADD created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', ADD updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', ADD last_seen DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE vehicle ADD CONSTRAINT FK_1B80E486B03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE vehicle ADD CONSTRAINT FK_1B80E486896DBBDE FOREIGN KEY (updated_by_id) REFERENCES user (id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_1B80E486B03A8386 ON vehicle (created_by_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_1B80E486896DBBDE ON vehicle (updated_by_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE vehicle DROP FOREIGN KEY FK_1B80E486B03A8386
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE vehicle DROP FOREIGN KEY FK_1B80E486896DBBDE
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX IDX_1B80E486B03A8386 ON vehicle
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX IDX_1B80E486896DBBDE ON vehicle
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE vehicle DROP created_by_id, DROP updated_by_id, DROP vin, DROP license_plate, DROP created_at, DROP updated_at, DROP last_seen
        SQL);
    }
}
