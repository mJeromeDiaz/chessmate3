<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 2 puzzle play: attempts, current ratings and rating history. Constraint names are explicit
 * (domain-prefixed); Doctrine ignores foreign-key names when diffing, so they stay stable.
 */
final class Version20260928144711 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create puzzle_attempt, puzzle_rating and puzzle_rating_change';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE puzzle_attempt (id BINARY(16) NOT NULL, rated TINYINT NOT NULL, rated_puzzle_id INT UNSIGNED GENERATED ALWAYS AS (IF(rated, puzzle_id, NULL)) STORED, status VARCHAR(10) NOT NULL, started_at DATETIME NOT NULL, submitted_at DATETIME DEFAULT NULL, duration_ms INT UNSIGNED DEFAULT NULL, moves JSON DEFAULT NULL, mistakes SMALLINT UNSIGNED DEFAULT 0 NOT NULL, hint_level SMALLINT UNSIGNED DEFAULT 0 NOT NULL, solution_shown TINYINT DEFAULT 0 NOT NULL, user_id BINARY(16) NOT NULL, puzzle_id INT UNSIGNED NOT NULL, rating_change_id BINARY(16) DEFAULT NULL, UNIQUE INDEX uniq_puzzle_attempt_rating_change (rating_change_id), INDEX idx_puzzle_attempt_user_started (user_id, started_at), INDEX idx_puzzle_attempt_user_status (user_id, status, started_at), UNIQUE INDEX uniq_puzzle_attempt_user_rated_puzzle (user_id, rated_puzzle_id), INDEX idx_puzzle_attempt_user (user_id), INDEX idx_puzzle_attempt_puzzle (puzzle_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE puzzle_rating (rating DOUBLE PRECISION NOT NULL, deviation DOUBLE PRECISION NOT NULL, volatility DOUBLE PRECISION NOT NULL, rated_count INT UNSIGNED DEFAULT 0 NOT NULL, last_rated_at DATETIME DEFAULT NULL, source VARCHAR(20) NOT NULL, updated_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, PRIMARY KEY (user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE puzzle_rating_change (id BINARY(16) NOT NULL, reason VARCHAR(20) NOT NULL, rating_before DOUBLE PRECISION NOT NULL, rating_after DOUBLE PRECISION NOT NULL, deviation_before DOUBLE PRECISION NOT NULL, deviation_after DOUBLE PRECISION NOT NULL, volatility_before DOUBLE PRECISION NOT NULL, volatility_after DOUBLE PRECISION NOT NULL, created_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, INDEX idx_puzzle_rating_change_user_date (user_id, created_at), INDEX idx_puzzle_rating_change_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE puzzle_attempt ADD CONSTRAINT fk_puzzle_attempt_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE puzzle_attempt ADD CONSTRAINT fk_puzzle_attempt_puzzle FOREIGN KEY (puzzle_id) REFERENCES puzzle (id)');
        $this->addSql('ALTER TABLE puzzle_attempt ADD CONSTRAINT fk_puzzle_attempt_rating_change FOREIGN KEY (rating_change_id) REFERENCES puzzle_rating_change (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE puzzle_rating ADD CONSTRAINT fk_puzzle_rating_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE puzzle_rating_change ADD CONSTRAINT fk_puzzle_rating_change_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle_attempt DROP FOREIGN KEY fk_puzzle_attempt_user');
        $this->addSql('ALTER TABLE puzzle_attempt DROP FOREIGN KEY fk_puzzle_attempt_puzzle');
        $this->addSql('ALTER TABLE puzzle_attempt DROP FOREIGN KEY fk_puzzle_attempt_rating_change');
        $this->addSql('ALTER TABLE puzzle_rating DROP FOREIGN KEY fk_puzzle_rating_user');
        $this->addSql('ALTER TABLE puzzle_rating_change DROP FOREIGN KEY fk_puzzle_rating_change_user');
        $this->addSql('DROP TABLE puzzle_attempt');
        $this->addSql('DROP TABLE puzzle_rating');
        $this->addSql('DROP TABLE puzzle_rating_change');
    }
}
