<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Puzzle streak (docs/PUZZLE_STREAK.md): the streaks of each run and their puzzles.
 */
final class Version20261017090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Puzzle streak: streaks and their puzzles';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE puzzle_streak (id BINARY(16) NOT NULL, ramp VARCHAR(8) NOT NULL, topic VARCHAR(80) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, length SMALLINT UNSIGNED DEFAULT 0 NOT NULL, best_rating SMALLINT UNSIGNED DEFAULT NULL, status VARCHAR(8) NOT NULL, record TINYINT DEFAULT 0 NOT NULL, started_at DATETIME NOT NULL, ended_at DATETIME DEFAULT NULL, user_id BINARY(16) NOT NULL, run_id BINARY(16) NOT NULL, INDEX idx_puzzle_streak_run (run_id, started_at), INDEX idx_puzzle_streak_user_topic (user_id, ramp, topic, length), INDEX IDX_AB062C7FA76ED395 (user_id), INDEX IDX_AB062C7F84E3FEC4 (run_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE puzzle_streak ADD CONSTRAINT FK_AB062C7FA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE puzzle_streak ADD CONSTRAINT FK_AB062C7F84E3FEC4 FOREIGN KEY (run_id) REFERENCES training_run (id) ON DELETE CASCADE');
        $this->addSql('CREATE TABLE puzzle_streak_attempt (id BINARY(16) NOT NULL, puzzle_id INT UNSIGNED NOT NULL, puzzle_rating SMALLINT UNSIGNED NOT NULL, position SMALLINT UNSIGNED NOT NULL, target_rating SMALLINT UNSIGNED NOT NULL, status VARCHAR(8) NOT NULL, started_at DATETIME NOT NULL, submitted_at DATETIME DEFAULT NULL, duration_ms INT UNSIGNED DEFAULT NULL, moves JSON DEFAULT NULL, mistakes SMALLINT UNSIGNED DEFAULT 0 NOT NULL, user_id BINARY(16) NOT NULL, run_id BINARY(16) NOT NULL, streak_id BINARY(16) NOT NULL, INDEX idx_puzzle_streak_attempt_run (run_id, started_at), INDEX idx_puzzle_streak_attempt_user_puzzle (user_id, puzzle_id), INDEX IDX_C0677E55A76ED395 (user_id), INDEX IDX_C0677E5584E3FEC4 (run_id), INDEX IDX_C0677E55F58619E2 (streak_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE puzzle_streak_attempt ADD CONSTRAINT FK_C0677E55A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE puzzle_streak_attempt ADD CONSTRAINT FK_C0677E5584E3FEC4 FOREIGN KEY (run_id) REFERENCES training_run (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE puzzle_streak_attempt ADD CONSTRAINT FK_C0677E55F58619E2 FOREIGN KEY (streak_id) REFERENCES puzzle_streak (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle_streak_attempt DROP FOREIGN KEY FK_C0677E55A76ED395');
        $this->addSql('ALTER TABLE puzzle_streak_attempt DROP FOREIGN KEY FK_C0677E5584E3FEC4');
        $this->addSql('ALTER TABLE puzzle_streak_attempt DROP FOREIGN KEY FK_C0677E55F58619E2');
        $this->addSql('DROP TABLE puzzle_streak_attempt');
        $this->addSql('ALTER TABLE puzzle_streak DROP FOREIGN KEY FK_AB062C7FA76ED395');
        $this->addSql('ALTER TABLE puzzle_streak DROP FOREIGN KEY FK_AB062C7F84E3FEC4');
        $this->addSql('DROP TABLE puzzle_streak');
    }
}
