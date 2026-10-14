<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The store's pardon on a rated puzzle missed (docs/PUZZLES.md, docs/SHOP.md): the attempt is
 * marked, the rating history gets a `pardon` step (no schema change: the reason is a string).
 */
final class Version20261019120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Puzzle pardons: puzzle_attempt.pardoned';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle_attempt ADD pardoned TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle_attempt DROP pardoned');
    }
}
