<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261004083723 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Puzzle attempts played in a timed run (puzzle_attempt.training_run_id)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle_attempt ADD training_run_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_attempt ADD CONSTRAINT fk_puzzle_attempt_training_run FOREIGN KEY (training_run_id) REFERENCES training_run (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_puzzle_attempt_training_run_status ON puzzle_attempt (training_run_id, status)');
        $this->addSql('CREATE INDEX idx_puzzle_attempt_training_run ON puzzle_attempt (training_run_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle_attempt DROP FOREIGN KEY fk_puzzle_attempt_training_run');
        $this->addSql('DROP INDEX idx_puzzle_attempt_training_run_status ON puzzle_attempt');
        $this->addSql('DROP INDEX idx_puzzle_attempt_training_run ON puzzle_attempt');
        $this->addSql('ALTER TABLE puzzle_attempt DROP training_run_id');
    }
}
