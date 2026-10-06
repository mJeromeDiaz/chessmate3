<?php

declare(strict_types=1);

namespace DoctrineMigrationsCatalog;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The catalogue's tables, as the main database had them up to Version20261009100000 (which drops
 * them there). IF NOT EXISTS: tables moved over with RENAME TABLE (docs/DEPLOY_OVH.md, § 3) are kept.
 */
final class Version20261009100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Puzzle catalogue: puzzle, puzzle_theme, puzzle_theme_membership';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS puzzle (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                lichess_id CHAR(5) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`,
                fen VARCHAR(92) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`,
                moves VARCHAR(255) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`,
                rating SMALLINT UNSIGNED NOT NULL,
                rating_deviation SMALLINT UNSIGNED NOT NULL,
                popularity SMALLINT NOT NULL,
                nb_plays INT UNSIGNED NOT NULL,
                themes JSON NOT NULL,
                opening_tags JSON DEFAULT NULL,
                game_url VARCHAR(255) NOT NULL,
                daily_date DATE DEFAULT NULL,
                random_key INT UNSIGNED NOT NULL,
                selectable TINYINT NOT NULL,
                UNIQUE INDEX uniq_puzzle_lichess_id (lichess_id),
                INDEX idx_puzzle_selection (selectable, rating, random_key),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS puzzle_theme (
                id SMALLINT UNSIGNED AUTO_INCREMENT NOT NULL,
                theme_key VARCHAR(32) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`,
                category VARCHAR(20) NOT NULL,
                label_en VARCHAR(64) NOT NULL,
                label_fr VARCHAR(64) NOT NULL,
                description_en VARCHAR(255) NOT NULL,
                description_fr VARCHAR(255) NOT NULL,
                position SMALLINT UNSIGNED NOT NULL,
                puzzle_count INT UNSIGNED DEFAULT 0 NOT NULL,
                UNIQUE INDEX uniq_puzzle_theme_key (theme_key),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS puzzle_theme_membership (
                theme_id SMALLINT UNSIGNED NOT NULL,
                rating SMALLINT UNSIGNED NOT NULL,
                random_key INT UNSIGNED NOT NULL,
                puzzle_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (theme_id, rating, random_key, puzzle_id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE puzzle_theme_membership');
        $this->addSql('DROP TABLE puzzle_theme');
        $this->addSql('DROP TABLE puzzle');
    }
}
