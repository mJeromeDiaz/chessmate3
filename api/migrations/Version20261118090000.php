<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Brillant ou Blunder » (docs/BRILLIANCY.md): the positions served in timed runs and their verdicts.
 */
final class Version20261118090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Brillant ou Blunder: brilliancy_attempt';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE brilliancy_attempt (
                id BINARY(16) NOT NULL,
                puzzle_id INT UNSIGNED NOT NULL,
                puzzle_rating SMALLINT UNSIGNED NOT NULL,
                kind VARCHAR(9) NOT NULL,
                fen VARCHAR(92) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`,
                move VARCHAR(5) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`,
                served_at DATETIME NOT NULL,
                seconds SMALLINT UNSIGNED NOT NULL,
                status VARCHAR(8) NOT NULL,
                answer VARCHAR(9) DEFAULT NULL,
                submitted_at DATETIME DEFAULT NULL,
                duration_ms INT UNSIGNED DEFAULT NULL,
                user_id BINARY(16) NOT NULL,
                run_id BINARY(16) NOT NULL,
                INDEX IDX_2730F810A76ED395 (user_id),
                INDEX IDX_2730F81084E3FEC4 (run_id),
                INDEX idx_brilliancy_attempt_run (run_id, served_at),
                INDEX idx_brilliancy_attempt_user_puzzle (user_id, puzzle_id),
                INDEX idx_brilliancy_attempt_user_submitted (user_id, submitted_at),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
        $this->addSql('ALTER TABLE brilliancy_attempt ADD CONSTRAINT FK_2730F810A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE brilliancy_attempt ADD CONSTRAINT FK_2730F81084E3FEC4 FOREIGN KEY (run_id) REFERENCES training_run (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brilliancy_attempt DROP FOREIGN KEY FK_2730F810A76ED395');
        $this->addSql('ALTER TABLE brilliancy_attempt DROP FOREIGN KEY FK_2730F81084E3FEC4');
        $this->addSql('DROP TABLE brilliancy_attempt');
    }
}
