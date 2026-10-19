<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Puzzle rush (docs/PUZZLE_RUSH.md): the puzzles of the rushes (puzzle_rush_attempt).
 */
final class Version20261026090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Puzzle rush: puzzle_rush_attempt';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE puzzle_rush_attempt (id BINARY(16) NOT NULL, puzzle_id INT UNSIGNED NOT NULL, puzzle_rating SMALLINT UNSIGNED NOT NULL, position SMALLINT UNSIGNED NOT NULL, target_rating SMALLINT UNSIGNED NOT NULL, status VARCHAR(8) NOT NULL, started_at DATETIME NOT NULL, submitted_at DATETIME DEFAULT NULL, duration_ms INT UNSIGNED DEFAULT NULL, moves JSON DEFAULT NULL, mistakes SMALLINT UNSIGNED DEFAULT 0 NOT NULL, user_id BINARY(16) NOT NULL, run_id BINARY(16) NOT NULL, INDEX idx_puzzle_rush_attempt_run (run_id, started_at), INDEX idx_puzzle_rush_attempt_user_puzzle (user_id, puzzle_id), INDEX IDX_6711AA61A76ED395 (user_id), INDEX IDX_6711AA6184E3FEC4 (run_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE puzzle_rush_attempt ADD CONSTRAINT FK_6711AA61A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE puzzle_rush_attempt ADD CONSTRAINT FK_6711AA6184E3FEC4 FOREIGN KEY (run_id) REFERENCES training_run (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle_rush_attempt DROP FOREIGN KEY FK_6711AA61A76ED395');
        $this->addSql('ALTER TABLE puzzle_rush_attempt DROP FOREIGN KEY FK_6711AA6184E3FEC4');
        $this->addSql('DROP TABLE puzzle_rush_attempt');
    }
}
