<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Blindfold: puzzle attempts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE blindfold_puzzle_attempt (id BINARY(16) NOT NULL, puzzle_id INT UNSIGNED NOT NULL, level VARCHAR(8) NOT NULL, length SMALLINT UNSIGNED NOT NULL, visible_seconds SMALLINT UNSIGNED NOT NULL, status VARCHAR(8) NOT NULL, started_at DATETIME NOT NULL, submitted_at DATETIME DEFAULT NULL, duration_ms INT UNSIGNED DEFAULT NULL, moves JSON DEFAULT NULL, mistakes SMALLINT UNSIGNED DEFAULT 0 NOT NULL, user_id BINARY(16) NOT NULL, run_id BINARY(16) NOT NULL, INDEX idx_blindfold_puzzle_attempt_run (run_id, started_at), INDEX idx_blindfold_puzzle_attempt_user_puzzle (user_id, puzzle_id), INDEX idx_blindfold_puzzle_attempt_user_submitted (user_id, submitted_at), INDEX IDX_E51A5E54A76ED395 (user_id), INDEX IDX_E51A5E5484E3FEC4 (run_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE blindfold_puzzle_attempt ADD CONSTRAINT FK_E51A5E54A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE blindfold_puzzle_attempt ADD CONSTRAINT FK_E51A5E5484E3FEC4 FOREIGN KEY (run_id) REFERENCES training_run (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE blindfold_puzzle_attempt');
    }
}
