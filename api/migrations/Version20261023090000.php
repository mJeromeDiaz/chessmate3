<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Parcours (docs/PARCOURS.md): the puzzles of its tiers, never rated; a session marked as a
 * parcours (its theme, effort and repertoire), its tiers ended by their counts.
 */
final class Version20261023090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Parcours: the puzzles of its tiers (parcours_attempt), a session marked as a parcours (training_session.parcours)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE parcours_attempt (id BINARY(16) NOT NULL, puzzle_id INT UNSIGNED NOT NULL, puzzle_rating SMALLINT UNSIGNED NOT NULL, position SMALLINT UNSIGNED NOT NULL, target_rating SMALLINT UNSIGNED NOT NULL, status VARCHAR(8) NOT NULL, started_at DATETIME NOT NULL, submitted_at DATETIME DEFAULT NULL, duration_ms INT UNSIGNED DEFAULT NULL, moves JSON DEFAULT NULL, mistakes SMALLINT UNSIGNED DEFAULT 0 NOT NULL, helped TINYINT DEFAULT 0 NOT NULL, user_id BINARY(16) NOT NULL, run_id BINARY(16) NOT NULL, INDEX idx_parcours_attempt_run (run_id, position), INDEX idx_parcours_attempt_user_puzzle (user_id, puzzle_id), INDEX IDX_97873C30A76ED395 (user_id), INDEX IDX_97873C3084E3FEC4 (run_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE parcours_attempt ADD CONSTRAINT FK_97873C30A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE parcours_attempt ADD CONSTRAINT FK_97873C3084E3FEC4 FOREIGN KEY (run_id) REFERENCES training_run (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE training_session ADD parcours JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parcours_attempt DROP FOREIGN KEY FK_97873C30A76ED395');
        $this->addSql('ALTER TABLE parcours_attempt DROP FOREIGN KEY FK_97873C3084E3FEC4');
        $this->addSql('DROP TABLE parcours_attempt');
        $this->addSql('ALTER TABLE training_session DROP parcours');
    }
}
