<?php

declare(strict_types=1);

namespace DoctrineMigrationsEndgame;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The endgames module's positions, checked by the tablebase (docs/ENDGAMES.md, § 4).
 */
final class Version20261112100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Endgame database: endgame_position';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE endgame_position (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                lesson_key VARCHAR(32) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`,
                fen VARCHAR(92) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`,
                fen_hash BINARY(16) NOT NULL,
                dtm SMALLINT UNSIGNED DEFAULT NULL,
                textbook TINYINT NOT NULL,
                random_key INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_endgame_position_lesson_fen (lesson_key, fen_hash),
                INDEX idx_endgame_position_pick (lesson_key, random_key),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE endgame_position');
    }
}
