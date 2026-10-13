<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Repertoire from my games (docs/REPERTOIRE.md): what the games said of each move, kept with the
 * repertoire, and where a repertoire came from.
 */
final class Version20261015100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Repertoire: statistics of the games a repertoire was built from, and its origin';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE repertoire_game_stat (id BINARY(16) NOT NULL, fen_hash BINARY(16) NOT NULL, fen VARCHAR(92) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, uci VARCHAR(5) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, games INT UNSIGNED NOT NULL, wins INT UNSIGNED NOT NULL, draws INT UNSIGNED NOT NULL, losses INT UNSIGNED NOT NULL, analysed INT UNSIGNED NOT NULL, errored INT UNSIGNED NOT NULL, think_cs INT UNSIGNED DEFAULT NULL, move_analysed INT UNSIGNED NOT NULL, move_errors INT UNSIGNED NOT NULL, best_uci VARCHAR(5) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`, eval_cp INT DEFAULT NULL, eval_mate SMALLINT DEFAULT NULL, created_at DATETIME NOT NULL, repertoire_id BINARY(16) NOT NULL, INDEX idx_repertoire_game_stat_repertoire (repertoire_id), UNIQUE INDEX uniq_repertoire_game_stat_key (repertoire_id, fen_hash, uci), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE repertoire_game_stat ADD CONSTRAINT FK_1A7C33DE1E61B789 FOREIGN KEY (repertoire_id) REFERENCES repertoire (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE repertoire ADD origin JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE repertoire DROP origin');
        $this->addSql('ALTER TABLE repertoire_game_stat DROP FOREIGN KEY FK_1A7C33DE1E61B789');
        $this->addSql('DROP TABLE repertoire_game_stat');
    }
}
