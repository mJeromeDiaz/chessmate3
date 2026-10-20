<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Vision (docs/VISION.md): the questions asked (vision_question).
 */
final class Version20261104090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vision: vision_question';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE vision_question (id BINARY(16) NOT NULL, kind VARCHAR(16) NOT NULL, ask VARCHAR(12) NOT NULL, fen VARCHAR(92) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, announced JSON NOT NULL, target VARCHAR(2) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`, side VARCHAR(5) DEFAULT NULL, expected JSON NOT NULL, puzzle_id INT UNSIGNED DEFAULT NULL, visible_seconds SMALLINT UNSIGNED NOT NULL, status VARCHAR(8) NOT NULL, answer JSON DEFAULT NULL, started_at DATETIME NOT NULL, submitted_at DATETIME DEFAULT NULL, duration_ms INT UNSIGNED DEFAULT NULL, user_id BINARY(16) NOT NULL, run_id BINARY(16) NOT NULL, INDEX idx_vision_question_run (run_id, started_at), INDEX idx_vision_question_user_puzzle (user_id, puzzle_id), INDEX IDX_110775BEA76ED395 (user_id), INDEX IDX_110775BE84E3FEC4 (run_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE vision_question ADD CONSTRAINT FK_110775BEA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE vision_question ADD CONSTRAINT FK_110775BE84E3FEC4 FOREIGN KEY (run_id) REFERENCES training_run (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vision_question DROP FOREIGN KEY FK_110775BEA76ED395');
        $this->addSql('ALTER TABLE vision_question DROP FOREIGN KEY FK_110775BE84E3FEC4');
        $this->addSql('DROP TABLE vision_question');
    }
}
