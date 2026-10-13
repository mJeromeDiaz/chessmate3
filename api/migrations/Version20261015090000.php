<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Repertoire from my games (docs/REPERTOIRE.md): a build reading the user's Lichess games, one per
 * user, forgotten after a day.
 */
final class Version20261015090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Repertoire: builds from the user\'s Lichess games';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE repertoire_auto_build (id BINARY(16) NOT NULL, status VARCHAR(16) NOT NULL, username VARCHAR(30) NOT NULL, max_games SMALLINT UNSIGNED NOT NULL, perfs JSON NOT NULL, until_ms BIGINT DEFAULT NULL, scanned INT UNSIGNED DEFAULT 0 NOT NULL, game_count INT UNSIGNED DEFAULT 0 NOT NULL, games LONGTEXT NOT NULL, error VARCHAR(32) DEFAULT NULL, selection JSON DEFAULT NULL, evaluations JSON NOT NULL, repertoire_ids JSON NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, INDEX idx_repertoire_auto_build_user (user_id), INDEX idx_repertoire_auto_build_expires (expires_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE repertoire_auto_build ADD CONSTRAINT FK_AB2ECFEEA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE repertoire_auto_build DROP FOREIGN KEY FK_AB2ECFEEA76ED395');
        $this->addSql('DROP TABLE repertoire_auto_build');
    }
}
