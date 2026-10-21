<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Calculation (docs/CALCULATION.md): the lines calculated (calculation_attempt).
 */
final class Version20261106090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Calculation: calculation_attempt';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE calculation_attempt (id BINARY(16) NOT NULL, puzzle_id INT UNSIGNED NOT NULL, puzzle_rating SMALLINT UNSIGNED NOT NULL, mate SMALLINT UNSIGNED NOT NULL, fen VARCHAR(92) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, expected JSON NOT NULL, status VARCHAR(8) NOT NULL, answer JSON DEFAULT NULL, mistake_at SMALLINT UNSIGNED DEFAULT NULL, started_at DATETIME NOT NULL, ready_at DATETIME DEFAULT NULL, submitted_at DATETIME DEFAULT NULL, duration_ms INT UNSIGNED DEFAULT NULL, user_id BINARY(16) NOT NULL, run_id BINARY(16) NOT NULL, INDEX idx_calculation_attempt_run (run_id, started_at), INDEX idx_calculation_attempt_user_puzzle (user_id, puzzle_id), INDEX IDX_1DB7FF16A76ED395 (user_id), INDEX IDX_1DB7FF1684E3FEC4 (run_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE calculation_attempt ADD CONSTRAINT FK_1DB7FF16A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE calculation_attempt ADD CONSTRAINT FK_1DB7FF1684E3FEC4 FOREIGN KEY (run_id) REFERENCES training_run (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calculation_attempt DROP FOREIGN KEY FK_1DB7FF16A76ED395');
        $this->addSql('ALTER TABLE calculation_attempt DROP FOREIGN KEY FK_1DB7FF1684E3FEC4');
        $this->addSql('DROP TABLE calculation_attempt');
    }
}
