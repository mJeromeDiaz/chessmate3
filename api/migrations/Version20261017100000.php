<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Overtime of timed runs (docs/TRAINING.md, "Prolongation"): until when an expired run waits for
 * the player's decision, and how many times it was extended.
 */
final class Version20261017100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Training: overtime of timed runs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE training_run ADD decision_until DATETIME DEFAULT NULL, ADD extensions SMALLINT UNSIGNED DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE training_run DROP decision_until, DROP extensions');
    }
}
