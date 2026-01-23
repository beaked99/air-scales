<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add virtual steer axle fields to device table
 */
final class Version20260118070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add virtual steer axle fields to device table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE device ADD has_virtual_steer TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE device ADD wheelbase DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE device ADD kingpin_distance DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE device ADD virtual_steer_intercept DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE device ADD virtual_steer_coeff DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE device DROP has_virtual_steer');
        $this->addSql('ALTER TABLE device DROP wheelbase');
        $this->addSql('ALTER TABLE device DROP kingpin_distance');
        $this->addSql('ALTER TABLE device DROP virtual_steer_intercept');
        $this->addSql('ALTER TABLE device DROP virtual_steer_coeff');
    }
}
