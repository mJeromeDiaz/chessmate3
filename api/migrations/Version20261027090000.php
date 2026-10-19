<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Position recall (docs/BLINDFOLD_RECALL.md): the positions rebuilt (blindfold_recall_attempt).
 * The puzzle catalogue's piece count is in migrations_catalog (Version20261027100000).
 */
final class Version20261027090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Position recall: blindfold_recall_attempt';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE blindfold_recall_attempt (id BINARY(16) NOT NULL, level SMALLINT UNSIGNED NOT NULL, visible_seconds SMALLINT UNSIGNED NOT NULL, fen VARCHAR(92) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, expected_placement VARCHAR(92) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, announced JSON NOT NULL, puzzle_id INT UNSIGNED DEFAULT NULL, piece_count SMALLINT UNSIGNED NOT NULL, status VARCHAR(8) NOT NULL, placement JSON DEFAULT NULL, correct SMALLINT UNSIGNED DEFAULT 0 NOT NULL, wrong SMALLINT UNSIGNED DEFAULT 0 NOT NULL, extra SMALLINT UNSIGNED DEFAULT 0 NOT NULL, missing SMALLINT UNSIGNED DEFAULT 0 NOT NULL, unlocked TINYINT DEFAULT 0 NOT NULL, started_at DATETIME NOT NULL, submitted_at DATETIME DEFAULT NULL, duration_ms INT UNSIGNED DEFAULT NULL, user_id BINARY(16) NOT NULL, run_id BINARY(16) NOT NULL, INDEX idx_blindfold_recall_attempt_run (run_id, started_at), INDEX idx_blindfold_recall_attempt_user_level (user_id, level, status), INDEX idx_blindfold_recall_attempt_user_puzzle (user_id, puzzle_id), INDEX IDX_B61E9AA76ED395 (user_id), INDEX IDX_B61E9A84E3FEC4 (run_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE blindfold_recall_attempt ADD CONSTRAINT FK_B61E9AA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE blindfold_recall_attempt ADD CONSTRAINT FK_B61E9A84E3FEC4 FOREIGN KEY (run_id) REFERENCES training_run (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blindfold_recall_attempt DROP FOREIGN KEY FK_B61E9AA76ED395');
        $this->addSql('ALTER TABLE blindfold_recall_attempt DROP FOREIGN KEY FK_B61E9A84E3FEC4');
        $this->addSql('DROP TABLE blindfold_recall_attempt');
    }
}
