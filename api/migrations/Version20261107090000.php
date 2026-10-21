<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Calculation (docs/CALCULATION.md, § 6): each line's thinking time (the Métronome's tempo).
 */
final class Version20261107090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Calculation: calculation_attempt.think_seconds';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calculation_attempt ADD think_seconds SMALLINT UNSIGNED DEFAULT 120 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calculation_attempt DROP think_seconds');
    }
}
