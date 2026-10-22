<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The endgames module (docs/ENDGAMES.md): the positions played in timed runs.
 */
final class Version20261112090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Endgames: endgame_attempt';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE endgame_attempt (id BINARY(16) NOT NULL, lesson_key VARCHAR(32) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, fen VARCHAR(100) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, position_id INT UNSIGNED DEFAULT NULL, textbook TINYINT NOT NULL, opponent_elo SMALLINT UNSIGNED DEFAULT NULL, budget_moves SMALLINT UNSIGNED DEFAULT NULL, hold_moves SMALLINT UNSIGNED DEFAULT NULL, served_at DATETIME NOT NULL, status VARCHAR(10) NOT NULL, moves JSON DEFAULT NULL, mistake_at SMALLINT UNSIGNED DEFAULT NULL, best_move VARCHAR(5) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`, player_moves SMALLINT UNSIGNED DEFAULT NULL, engine_judged TINYINT NOT NULL, submitted_at DATETIME DEFAULT NULL, duration_ms INT UNSIGNED DEFAULT NULL, user_id BINARY(16) NOT NULL, run_id BINARY(16) NOT NULL, INDEX idx_endgame_attempt_run (run_id, served_at), INDEX idx_endgame_attempt_user_submitted (user_id, submitted_at), INDEX IDX_D99D29C8A76ED395 (user_id), INDEX IDX_D99D29C884E3FEC4 (run_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE endgame_attempt ADD CONSTRAINT FK_D99D29C8A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE endgame_attempt ADD CONSTRAINT FK_D99D29C884E3FEC4 FOREIGN KEY (run_id) REFERENCES training_run (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE endgame_attempt DROP FOREIGN KEY FK_D99D29C8A76ED395');
        $this->addSql('ALTER TABLE endgame_attempt DROP FOREIGN KEY FK_D99D29C884E3FEC4');
        $this->addSql('DROP TABLE endgame_attempt');
    }
}
