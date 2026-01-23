<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add isShared column to truck_configuration table for fleet sharing
 */
final class Version20260117120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add isShared flag to truck_configuration for MVP fleet sharing';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE truck_configuration ADD is_shared TINYINT(1) NOT NULL DEFAULT 0
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE truck_configuration DROP is_shared
        SQL);
    }
}
