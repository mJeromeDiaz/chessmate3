<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Position evaluation (docs/EVALUATION.md): the hand-written positions and the attempts.
 */
final class Version20261013090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Evaluation: evaluation_position, evaluation_attempt';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE evaluation_attempt (id BINARY(16) NOT NULL, served_at DATETIME NOT NULL, seconds SMALLINT UNSIGNED NOT NULL, status VARCHAR(8) NOT NULL, guess SMALLINT DEFAULT NULL, plan VARCHAR(16) DEFAULT NULL, plan_ok TINYINT DEFAULT NULL, submitted_at DATETIME DEFAULT NULL, duration_ms INT UNSIGNED DEFAULT NULL, user_id BINARY(16) NOT NULL, run_id BINARY(16) NOT NULL, position_id BINARY(16) NOT NULL, INDEX idx_evaluation_attempt_run (run_id, served_at), INDEX idx_evaluation_attempt_user_position (user_id, position_id, served_at), INDEX idx_evaluation_attempt_user_submitted (user_id, submitted_at), INDEX IDX_3BFF1AECA76ED395 (user_id), INDEX IDX_3BFF1AEC84E3FEC4 (run_id), INDEX IDX_3BFF1AECDD842E46 (position_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE evaluation_position (id BINARY(16) NOT NULL, position_key VARCHAR(64) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, fen VARCHAR(100) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, turn VARCHAR(5) NOT NULL, eval_cp INT NOT NULL, plan VARCHAR(16) NOT NULL, ideas JSON NOT NULL, tip VARCHAR(255) NOT NULL, tag VARCHAR(12) NOT NULL, rating SMALLINT UNSIGNED NOT NULL, source VARCHAR(160) DEFAULT NULL, active TINYINT DEFAULT 1 NOT NULL, INDEX idx_evaluation_position_selection (active, turn, rating), UNIQUE INDEX uniq_evaluation_position_key (position_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE evaluation_attempt ADD CONSTRAINT FK_3BFF1AECA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE evaluation_attempt ADD CONSTRAINT FK_3BFF1AEC84E3FEC4 FOREIGN KEY (run_id) REFERENCES training_run (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE evaluation_attempt ADD CONSTRAINT FK_3BFF1AECDD842E46 FOREIGN KEY (position_id) REFERENCES evaluation_position (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE evaluation_attempt DROP FOREIGN KEY FK_3BFF1AECA76ED395');
        $this->addSql('ALTER TABLE evaluation_attempt DROP FOREIGN KEY FK_3BFF1AEC84E3FEC4');
        $this->addSql('ALTER TABLE evaluation_attempt DROP FOREIGN KEY FK_3BFF1AECDD842E46');
        $this->addSql('DROP TABLE evaluation_attempt');
        $this->addSql('DROP TABLE evaluation_position');
    }
}
