<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260115052918 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE orders ADD guest_email VARCHAR(255) DEFAULT NULL, ADD guest_name VARCHAR(100) DEFAULT NULL, ADD order_items JSON DEFAULT NULL, ADD subtotal NUMERIC(10, 2) DEFAULT NULL, ADD discount_amount NUMERIC(10, 2) DEFAULT NULL, CHANGE user_id user_id INT DEFAULT NULL, CHANGE product_id product_id INT DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE orders DROP guest_email, DROP guest_name, DROP order_items, DROP subtotal, DROP discount_amount, CHANGE user_id user_id INT NOT NULL, CHANGE product_id product_id INT NOT NULL
        SQL);
    }
}
