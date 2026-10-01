<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Timed repertoire tests (docs/REPERTOIRE.md): the presentations of segments
 * (repertoire_presentation, the unit of the statistics) and the state of a run
 * (repertoire_run_state).
 */
final class Version20260930154519 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Repertoire test presentations and run state';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE repertoire_presentation (id BINARY(16) NOT NULL, unit_id BINARY(16) NOT NULL, unit VARCHAR(8) NOT NULL, presentation_rank SMALLINT UNSIGNED NOT NULL, round SMALLINT UNSIGNED NOT NULL, status VARCHAR(12) NOT NULL, first_error_ply SMALLINT UNSIGNED DEFAULT NULL, positions_graded SMALLINT UNSIGNED DEFAULT 0 NOT NULL, moves JSON NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, duration_ms INT UNSIGNED DEFAULT NULL, user_id BINARY(16) NOT NULL, repertoire_id BINARY(16) NOT NULL, segment_id BINARY(16) NOT NULL, run_id BINARY(16) DEFAULT NULL, INDEX idx_repertoire_presentation_segment_finished (segment_id, finished_at), INDEX idx_repertoire_presentation_repertoire_finished (repertoire_id, finished_at), INDEX idx_repertoire_presentation_user_finished (user_id, finished_at), INDEX idx_repertoire_presentation_run (run_id), INDEX IDX_4B7A3D80A76ED395 (user_id), INDEX IDX_4B7A3D801E61B789 (repertoire_id), INDEX IDX_4B7A3D80DB296AAD (segment_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE repertoire_run_state (state JSON NOT NULL, run_id BINARY(16) NOT NULL, PRIMARY KEY (run_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE repertoire_presentation ADD CONSTRAINT FK_4B7A3D80A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE repertoire_presentation ADD CONSTRAINT FK_4B7A3D801E61B789 FOREIGN KEY (repertoire_id) REFERENCES repertoire (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE repertoire_presentation ADD CONSTRAINT FK_4B7A3D80DB296AAD FOREIGN KEY (segment_id) REFERENCES repertoire_segment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE repertoire_presentation ADD CONSTRAINT FK_4B7A3D8084E3FEC4 FOREIGN KEY (run_id) REFERENCES training_run (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE repertoire_run_state ADD CONSTRAINT FK_4EDD68A684E3FEC4 FOREIGN KEY (run_id) REFERENCES training_run (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE repertoire_presentation DROP FOREIGN KEY FK_4B7A3D80A76ED395');
        $this->addSql('ALTER TABLE repertoire_presentation DROP FOREIGN KEY FK_4B7A3D801E61B789');
        $this->addSql('ALTER TABLE repertoire_presentation DROP FOREIGN KEY FK_4B7A3D80DB296AAD');
        $this->addSql('ALTER TABLE repertoire_presentation DROP FOREIGN KEY FK_4B7A3D8084E3FEC4');
        $this->addSql('ALTER TABLE repertoire_run_state DROP FOREIGN KEY FK_4EDD68A684E3FEC4');
        $this->addSql('DROP TABLE repertoire_presentation');
        $this->addSql('DROP TABLE repertoire_run_state');
    }
}
