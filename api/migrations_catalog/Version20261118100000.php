<?php

declare(strict_types=1);

namespace DoctrineMigrationsCatalog;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Brillant ou Blunder » (docs/BRILLIANCY.md, § 2): the index of the puzzles that show a
 * sacrifice, filled by `app:brilliancy:rebuild`.
 */
final class Version20261118100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Puzzle catalogue: brilliancy_position';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE brilliancy_position (
                ply SMALLINT UNSIGNED NOT NULL,
                rating SMALLINT UNSIGNED NOT NULL,
                random_key INT UNSIGNED NOT NULL,
                puzzle_id INT UNSIGNED NOT NULL,
                INDEX idx_brilliancy_position_puzzle (puzzle_id),
                PRIMARY KEY (ply, rating, random_key, puzzle_id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE brilliancy_position');
    }
}
