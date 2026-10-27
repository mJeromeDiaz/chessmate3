<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The arena (docs/ARENA.md): the games against the bosses, and the bosses arrived in each player's
 * arena (their arrival shown or not).
 */
final class Version20261127090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Arena: arena_game, arena_unlock';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE arena_game (id BINARY(16) NOT NULL, boss VARCHAR(32) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, color VARCHAR(5) NOT NULL, elo SMALLINT UNSIGNED NOT NULL, cadence VARCHAR(8) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, increment_ms INT UNSIGNED NOT NULL, moves LONGTEXT CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, player_ms INT NOT NULL, boss_ms INT NOT NULL, turn_started_ms INT UNSIGNED NOT NULL, status VARCHAR(8) NOT NULL, end_reason VARCHAR(24) DEFAULT NULL, crystals INT UNSIGNED DEFAULT 0 NOT NULL, xp INT UNSIGNED DEFAULT 0 NOT NULL, started_at DATETIME NOT NULL, ended_at DATETIME DEFAULT NULL, user_id BINARY(16) NOT NULL, INDEX idx_arena_game_user_status (user_id, status), INDEX idx_arena_game_user_boss (user_id, boss, status), INDEX IDX_3BFC1F79A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE arena_unlock (id BINARY(16) NOT NULL, boss VARCHAR(32) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, unlocked_at DATETIME NOT NULL, announced_at DATETIME DEFAULT NULL, user_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_arena_unlock_user_boss (user_id, boss), INDEX IDX_DFF9B00FA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE arena_game ADD CONSTRAINT FK_3BFC1F79A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE arena_unlock ADD CONSTRAINT FK_DFF9B00FA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE arena_game DROP FOREIGN KEY FK_3BFC1F79A76ED395');
        $this->addSql('ALTER TABLE arena_unlock DROP FOREIGN KEY FK_DFF9B00FA76ED395');
        $this->addSql('DROP TABLE arena_game');
        $this->addSql('DROP TABLE arena_unlock');
    }
}
