<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250630052432 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE device ADD regression_intercept DOUBLE PRECISION DEFAULT NULL, ADD regression_air_pressure_coeff DOUBLE PRECISION DEFAULT NULL, ADD regression_ambient_pressure_coeff DOUBLE PRECISION DEFAULT NULL, ADD regression_air_temp_coeff DOUBLE PRECISION DEFAULT NULL, ADD regression_rsq DOUBLE PRECISION DEFAULT NULL, ADD regression_rmse DOUBLE PRECISION DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE device DROP regression_intercept, DROP regression_air_pressure_coeff, DROP regression_ambient_pressure_coeff, DROP regression_air_temp_coeff, DROP regression_rsq, DROP regression_rmse
        SQL);
    }
}
