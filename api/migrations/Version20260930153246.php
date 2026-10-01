<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Spaced-repetition cards of the repertoire test (docs/REPERTOIRE.md, FSRS-6): repertoire_card,
 * keyed by (repertoire, position, expected move) and created at the first answer, and the
 * append-only log of the answers, repertoire_review.
 */
final class Version20260930153246 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Repertoire cards (FSRS) and review log';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE repertoire_card (id BINARY(16) NOT NULL, fen_hash BINARY(16) NOT NULL, fen VARCHAR(92) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, uci VARCHAR(5) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, state SMALLINT UNSIGNED NOT NULL, step SMALLINT UNSIGNED DEFAULT NULL, stability DOUBLE PRECISION DEFAULT NULL, difficulty DOUBLE PRECISION DEFAULT NULL, due DATETIME NOT NULL, last_review DATETIME DEFAULT NULL, reps INT UNSIGNED DEFAULT 0 NOT NULL, lapses INT UNSIGNED DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, repertoire_id BINARY(16) NOT NULL, INDEX idx_repertoire_card_repertoire_due (repertoire_id, due), INDEX idx_repertoire_card_repertoire (repertoire_id), UNIQUE INDEX uniq_repertoire_card_key (repertoire_id, fen_hash, uci), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE repertoire_review (id BINARY(16) NOT NULL, played_uci VARCHAR(5) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, correct TINYINT NOT NULL, rating SMALLINT UNSIGNED NOT NULL, think_ms INT UNSIGNED NOT NULL, updated TINYINT NOT NULL, card_before JSON NOT NULL, card_after JSON DEFAULT NULL, reviewed_at DATETIME NOT NULL, card_id BINARY(16) NOT NULL, run_id BINARY(16) DEFAULT NULL, INDEX idx_repertoire_review_card_reviewed (card_id, reviewed_at), INDEX idx_repertoire_review_run (run_id), INDEX IDX_7C2506C74ACC9A20 (card_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE repertoire_card ADD CONSTRAINT FK_C03D95091E61B789 FOREIGN KEY (repertoire_id) REFERENCES repertoire (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE repertoire_review ADD CONSTRAINT FK_7C2506C74ACC9A20 FOREIGN KEY (card_id) REFERENCES repertoire_card (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE repertoire_review ADD CONSTRAINT FK_7C2506C784E3FEC4 FOREIGN KEY (run_id) REFERENCES training_run (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE repertoire_card DROP FOREIGN KEY FK_C03D95091E61B789');
        $this->addSql('ALTER TABLE repertoire_review DROP FOREIGN KEY FK_7C2506C74ACC9A20');
        $this->addSql('ALTER TABLE repertoire_review DROP FOREIGN KEY FK_7C2506C784E3FEC4');
        $this->addSql('DROP TABLE repertoire_card');
        $this->addSql('DROP TABLE repertoire_review');
    }
}
