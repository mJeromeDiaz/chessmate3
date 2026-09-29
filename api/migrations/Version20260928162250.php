<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Woodpecker: sets (one ongoing per user, enforced by a generated column), their frozen puzzle
 * lists, cycle runs and attempts. Puzzle foreign keys have no cascade (docs/PUZZLE_IMPORT.md).
 */
final class Version20260928162250 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create woodpecker_set, woodpecker_set_puzzle, woodpecker_cycle, woodpecker_attempt';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE woodpecker_attempt (id BINARY(16) NOT NULL, order_index SMALLINT UNSIGNED NOT NULL, status VARCHAR(10) NOT NULL, started_at DATETIME NOT NULL, submitted_at DATETIME DEFAULT NULL, duration_ms INT UNSIGNED DEFAULT NULL, moves JSON DEFAULT NULL, mistakes SMALLINT UNSIGNED DEFAULT 0 NOT NULL, hint_level SMALLINT UNSIGNED DEFAULT 0 NOT NULL, solution_shown TINYINT DEFAULT 0 NOT NULL, cycle_id BINARY(16) NOT NULL, puzzle_id INT UNSIGNED NOT NULL, INDEX idx_woodpecker_attempt_cycle_status (cycle_id, status), INDEX idx_woodpecker_attempt_puzzle (puzzle_id), INDEX idx_woodpecker_attempt_cycle (cycle_id), UNIQUE INDEX uniq_woodpecker_attempt_cycle_puzzle (cycle_id, puzzle_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE woodpecker_cycle (id BINARY(16) NOT NULL, number SMALLINT UNSIGNED NOT NULL, run SMALLINT UNSIGNED NOT NULL, status VARCHAR(16) NOT NULL, duration_days SMALLINT UNSIGNED NOT NULL, seed INT UNSIGNED NOT NULL, available_at DATETIME NOT NULL, deadline_at DATETIME NOT NULL, completed_at DATETIME DEFAULT NULL, lost_at DATETIME DEFAULT NULL, set_id BINARY(16) NOT NULL, INDEX idx_woodpecker_cycle_set_status (set_id, status), INDEX idx_woodpecker_cycle_set (set_id), UNIQUE INDEX uniq_woodpecker_cycle_set_number_run (set_id, number, run), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE woodpecker_set (id BINARY(16) NOT NULL, name VARCHAR(80) NOT NULL, status VARCHAR(16) NOT NULL, active_user_id BINARY(16) GENERATED ALWAYS AS (IF(status IN (\'active\', \'paused\'), user_id, NULL)) VIRTUAL, puzzle_count SMALLINT UNSIGNED NOT NULL, rating_min SMALLINT UNSIGNED NOT NULL, rating_max SMALLINT UNSIGNED NOT NULL, themes JSON NOT NULL, cycle_count SMALLINT UNSIGNED NOT NULL, first_cycle_days SMALLINT UNSIGNED NOT NULL, reduction_factor DOUBLE PRECISION NOT NULL, min_cycle_days SMALLINT UNSIGNED NOT NULL, rest_days SMALLINT UNSIGNED NOT NULL, shuffle TINYINT NOT NULL, created_at DATETIME NOT NULL, paused_at DATETIME DEFAULT NULL, completed_at DATETIME DEFAULT NULL, abandoned_at DATETIME DEFAULT NULL, archived_at DATETIME DEFAULT NULL, user_id BINARY(16) NOT NULL, INDEX idx_woodpecker_set_user_created (user_id, created_at), INDEX idx_woodpecker_set_user (user_id), UNIQUE INDEX uniq_woodpecker_set_active_user (active_user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE woodpecker_set_puzzle (position SMALLINT UNSIGNED NOT NULL, set_id BINARY(16) NOT NULL, puzzle_id INT UNSIGNED NOT NULL, INDEX idx_woodpecker_set_puzzle_puzzle (puzzle_id), INDEX idx_woodpecker_set_puzzle_set (set_id), UNIQUE INDEX uniq_woodpecker_set_puzzle_set_puzzle (set_id, puzzle_id), PRIMARY KEY (set_id, position)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE woodpecker_attempt ADD CONSTRAINT fk_woodpecker_attempt_cycle FOREIGN KEY (cycle_id) REFERENCES woodpecker_cycle (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE woodpecker_attempt ADD CONSTRAINT fk_woodpecker_attempt_puzzle FOREIGN KEY (puzzle_id) REFERENCES puzzle (id)');
        $this->addSql('ALTER TABLE woodpecker_cycle ADD CONSTRAINT fk_woodpecker_cycle_set FOREIGN KEY (set_id) REFERENCES woodpecker_set (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE woodpecker_set ADD CONSTRAINT fk_woodpecker_set_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE woodpecker_set_puzzle ADD CONSTRAINT fk_woodpecker_set_puzzle_set FOREIGN KEY (set_id) REFERENCES woodpecker_set (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE woodpecker_set_puzzle ADD CONSTRAINT fk_woodpecker_set_puzzle_puzzle FOREIGN KEY (puzzle_id) REFERENCES puzzle (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE woodpecker_attempt DROP FOREIGN KEY fk_woodpecker_attempt_cycle');
        $this->addSql('ALTER TABLE woodpecker_attempt DROP FOREIGN KEY fk_woodpecker_attempt_puzzle');
        $this->addSql('ALTER TABLE woodpecker_cycle DROP FOREIGN KEY fk_woodpecker_cycle_set');
        $this->addSql('ALTER TABLE woodpecker_set DROP FOREIGN KEY fk_woodpecker_set_user');
        $this->addSql('ALTER TABLE woodpecker_set_puzzle DROP FOREIGN KEY fk_woodpecker_set_puzzle_set');
        $this->addSql('ALTER TABLE woodpecker_set_puzzle DROP FOREIGN KEY fk_woodpecker_set_puzzle_puzzle');
        $this->addSql('DROP TABLE woodpecker_attempt');
        $this->addSql('DROP TABLE woodpecker_cycle');
        $this->addSql('DROP TABLE woodpecker_set');
        $this->addSql('DROP TABLE woodpecker_set_puzzle');
    }
}
