<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Calculation (docs/CALCULATION.md, § 2): lines without mate, so a line's length in the player's
 * moves (`mate` renamed `moves`) and how it ends.
 */
final class Version20261108090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Calculation: calculation_attempt.moves, ending';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calculation_attempt CHANGE mate moves SMALLINT UNSIGNED NOT NULL, ADD ending VARCHAR(8) DEFAULT \'mate\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calculation_attempt CHANGE moves mate SMALLINT UNSIGNED NOT NULL, DROP ending');
    }
}
