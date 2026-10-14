<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The store's pardons on blindfold puzzles and coordinates series (docs/BLINDFOLD.md,
 * docs/COORDINATES.md, docs/SHOP.md).
 */
final class Version20261019130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Blindfold and coordinates pardons: blindfold_puzzle_attempt.pardon, coordinates_series.pardoned_index';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blindfold_puzzle_attempt ADD pardon VARCHAR(4) DEFAULT NULL');
        $this->addSql('ALTER TABLE coordinates_series ADD pardoned_index SMALLINT UNSIGNED DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blindfold_puzzle_attempt DROP pardon');
        $this->addSql('ALTER TABLE coordinates_series DROP pardoned_index');
    }
}
