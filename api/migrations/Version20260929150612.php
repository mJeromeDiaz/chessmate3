<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Opening repertoires (docs/REPERTOIRE.md): graph of positions and moves, segments, undo journal.
 * The generated columns (one reference move per position, one canonical move into a position,
 * one active segment per start) are VIRTUAL: MySQL refuses STORED ones over ON DELETE CASCADE
 * foreign keys.
 */
final class Version20260929150612 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create repertoire, repertoire_position, repertoire_move, repertoire_segment, repertoire_revision';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE repertoire (id BINARY(16) NOT NULL, name VARCHAR(80) NOT NULL, color VARCHAR(8) NOT NULL, position_count INT UNSIGNED DEFAULT 1 NOT NULL, version INT UNSIGNED DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, INDEX idx_repertoire_user_created (user_id, created_at), INDEX idx_repertoire_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE repertoire_move (id BINARY(16) NOT NULL, uci VARCHAR(5) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, san VARCHAR(10) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, role VARCHAR(12) NOT NULL, sort_order SMALLINT UNSIGNED NOT NULL, comment LONGTEXT DEFAULT NULL, nags JSON NOT NULL, canonical TINYINT DEFAULT 0 NOT NULL, reference_from_position_id BINARY(16) GENERATED ALWAYS AS (IF(role = \'reference\', from_position_id, NULL)) VIRTUAL, canonical_to_position_id BINARY(16) GENERATED ALWAYS AS (IF(canonical = 1, to_position_id, NULL)) VIRTUAL, created_at DATETIME NOT NULL, repertoire_id BINARY(16) NOT NULL, from_position_id BINARY(16) NOT NULL, to_position_id BINARY(16) NOT NULL, segment_id BINARY(16) DEFAULT NULL, INDEX idx_repertoire_move_repertoire (repertoire_id), INDEX idx_repertoire_move_from (from_position_id), INDEX idx_repertoire_move_to (to_position_id), INDEX idx_repertoire_move_segment (segment_id), UNIQUE INDEX uniq_repertoire_move_from_uci (from_position_id, uci), UNIQUE INDEX uniq_repertoire_move_reference (reference_from_position_id), UNIQUE INDEX uniq_repertoire_move_canonical (canonical_to_position_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE repertoire_position (id BINARY(16) NOT NULL, fen VARCHAR(92) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, fen_hash BINARY(16) NOT NULL, turn CHAR(1) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, depth SMALLINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, repertoire_id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, INDEX idx_repertoire_position_user_hash (user_id, fen_hash), INDEX idx_repertoire_position_repertoire (repertoire_id), INDEX idx_repertoire_position_user (user_id), UNIQUE INDEX uniq_repertoire_position_fen (repertoire_id, fen_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE repertoire_revision (id BINARY(16) NOT NULL, version INT UNSIGNED NOT NULL, operation VARCHAR(16) NOT NULL, inverse JSON NOT NULL, created_at DATETIME NOT NULL, repertoire_id BINARY(16) NOT NULL, INDEX idx_repertoire_revision_repertoire_version (repertoire_id, version), INDEX idx_repertoire_revision_repertoire (repertoire_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE repertoire_segment (id BINARY(16) NOT NULL, start_move_id BINARY(16) DEFAULT NULL, derived_from_segment_id BINARY(16) DEFAULT NULL, merged_into_segment_id BINARY(16) DEFAULT NULL, move_count SMALLINT UNSIGNED DEFAULT 0 NOT NULL, user_move_count SMALLINT UNSIGNED DEFAULT 0 NOT NULL, archived_at DATETIME DEFAULT NULL, active_start_move_id BINARY(16) GENERATED ALWAYS AS (IF(archived_at IS NULL, start_move_id, NULL)) VIRTUAL, active_trunk_repertoire_id BINARY(16) GENERATED ALWAYS AS (IF(archived_at IS NULL AND start_move_id IS NULL, repertoire_id, NULL)) VIRTUAL, created_at DATETIME NOT NULL, repertoire_id BINARY(16) NOT NULL, INDEX idx_repertoire_segment_repertoire_archived (repertoire_id, archived_at), INDEX idx_repertoire_segment_repertoire (repertoire_id), INDEX idx_repertoire_segment_start (start_move_id), UNIQUE INDEX uniq_repertoire_segment_active_start (active_start_move_id), UNIQUE INDEX uniq_repertoire_segment_active_trunk (active_trunk_repertoire_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE repertoire ADD CONSTRAINT fk_repertoire_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE repertoire_move ADD CONSTRAINT fk_repertoire_move_repertoire FOREIGN KEY (repertoire_id) REFERENCES repertoire (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE repertoire_move ADD CONSTRAINT fk_repertoire_move_from FOREIGN KEY (from_position_id) REFERENCES repertoire_position (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE repertoire_move ADD CONSTRAINT fk_repertoire_move_to FOREIGN KEY (to_position_id) REFERENCES repertoire_position (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE repertoire_move ADD CONSTRAINT fk_repertoire_move_segment FOREIGN KEY (segment_id) REFERENCES repertoire_segment (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE repertoire_position ADD CONSTRAINT fk_repertoire_position_repertoire FOREIGN KEY (repertoire_id) REFERENCES repertoire (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE repertoire_position ADD CONSTRAINT fk_repertoire_position_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE repertoire_revision ADD CONSTRAINT fk_repertoire_revision_repertoire FOREIGN KEY (repertoire_id) REFERENCES repertoire (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE repertoire_segment ADD CONSTRAINT fk_repertoire_segment_repertoire FOREIGN KEY (repertoire_id) REFERENCES repertoire (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE repertoire DROP FOREIGN KEY fk_repertoire_user');
        $this->addSql('ALTER TABLE repertoire_move DROP FOREIGN KEY fk_repertoire_move_repertoire');
        $this->addSql('ALTER TABLE repertoire_move DROP FOREIGN KEY fk_repertoire_move_from');
        $this->addSql('ALTER TABLE repertoire_move DROP FOREIGN KEY fk_repertoire_move_to');
        $this->addSql('ALTER TABLE repertoire_move DROP FOREIGN KEY fk_repertoire_move_segment');
        $this->addSql('ALTER TABLE repertoire_position DROP FOREIGN KEY fk_repertoire_position_repertoire');
        $this->addSql('ALTER TABLE repertoire_position DROP FOREIGN KEY fk_repertoire_position_user');
        $this->addSql('ALTER TABLE repertoire_revision DROP FOREIGN KEY fk_repertoire_revision_repertoire');
        $this->addSql('ALTER TABLE repertoire_segment DROP FOREIGN KEY fk_repertoire_segment_repertoire');
        $this->addSql('DROP TABLE repertoire');
        $this->addSql('DROP TABLE repertoire_move');
        $this->addSql('DROP TABLE repertoire_position');
        $this->addSql('DROP TABLE repertoire_revision');
        $this->addSql('DROP TABLE repertoire_segment');
    }
}
