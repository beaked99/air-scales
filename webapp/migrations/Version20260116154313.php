<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260116154313 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE calibration ADD device_channel_id INT DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE calibration ADD CONSTRAINT FK_FCC2B413F5C6E26C FOREIGN KEY (device_channel_id) REFERENCES device_channel (id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_FCC2B413F5C6E26C ON calibration (device_channel_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE calibration DROP FOREIGN KEY FK_FCC2B413F5C6E26C
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX IDX_FCC2B413F5C6E26C ON calibration
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE calibration DROP device_channel_id
        SQL);
    }
}
