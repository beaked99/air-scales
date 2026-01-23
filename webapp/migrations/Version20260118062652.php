<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260118062652 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE truck_configuration ADD wheelbase DOUBLE PRECISION DEFAULT NULL, ADD has_virtual_steer TINYINT(1) NOT NULL, ADD virtual_steer_intercept DOUBLE PRECISION DEFAULT NULL, ADD virtual_steer_coeff DOUBLE PRECISION DEFAULT NULL, CHANGE is_shared is_shared TINYINT(1) NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE truck_configuration DROP wheelbase, DROP has_virtual_steer, DROP virtual_steer_intercept, DROP virtual_steer_coeff, CHANGE is_shared is_shared TINYINT(1) DEFAULT 0 NOT NULL
        SQL);
    }
}
