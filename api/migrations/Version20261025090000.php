<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Repertoire from my games (docs/REPERTOIRE.md § 12 bis): the explorer's precomputed first moves
 * (repertoire_opening_stats), the pending positions of the repertoires built (repertoire_pending),
 * the exploration of an analysis (repertoire_auto_build.exploration).
 */
final class Version20261025090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Repertoire from my games: repertoire_opening_stats, repertoire_pending, repertoire_auto_build.exploration';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE repertoire_opening_stats (id BINARY(16) NOT NULL, fen_hash BINARY(16) NOT NULL, fen VARCHAR(92) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, total INT UNSIGNED NOT NULL, moves JSON NOT NULL, fetched_at DATETIME NOT NULL, UNIQUE INDEX uniq_repertoire_opening_stats_fen (fen_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE repertoire_pending (id BINARY(16) NOT NULL, fen_hash BINARY(16) NOT NULL, fen VARCHAR(92) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, path JSON NOT NULL, reason VARCHAR(16) NOT NULL, games INT UNSIGNED DEFAULT NULL, share DOUBLE PRECISION DEFAULT NULL, score SMALLINT UNSIGNED DEFAULT NULL, status VARCHAR(12) NOT NULL, answer_uci VARCHAR(5) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, repertoire_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_repertoire_pending_position (repertoire_id, fen_hash), INDEX IDX_A3D72CA81E61B789 (repertoire_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE repertoire_pending ADD CONSTRAINT FK_A3D72CA81E61B789 FOREIGN KEY (repertoire_id) REFERENCES repertoire (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE repertoire_auto_build ADD exploration JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE repertoire_pending DROP FOREIGN KEY FK_A3D72CA81E61B789');
        $this->addSql('DROP TABLE repertoire_opening_stats');
        $this->addSql('DROP TABLE repertoire_pending');
        $this->addSql('ALTER TABLE repertoire_auto_build DROP exploration');
    }
}
