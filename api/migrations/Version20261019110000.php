<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Puzzle streak aids of the store (docs/PUZZLE_STREAK.md, docs/SHOP.md): the helps spent on a
 * puzzle; a passed puzzle is a new status value (no schema change).
 */
final class Version20261019110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Puzzle streak aids: puzzle_streak_attempt.helps';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle_streak_attempt ADD helps SMALLINT UNSIGNED DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle_streak_attempt DROP helps');
    }
}
