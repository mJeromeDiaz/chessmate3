<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Calculation (docs/CALCULATION.md, § 2 quater): chapter 3's kinds, judged by the engine in the
 * browser, and the second line of 3.2.
 */
final class Version20261110090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Calculation: calculation_attempt.kind, second_line';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE calculation_attempt ADD kind VARCHAR(12) DEFAULT 'line' NOT NULL, ADD second_line JSON DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calculation_attempt DROP kind, DROP second_line');
    }
}
