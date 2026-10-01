<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Opening names of repertoire positions (lichess-org/chess-openings, app:repertoire:sync-openings).
 */
final class Version20260929152146 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create repertoire_opening';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE repertoire_opening (id INT UNSIGNED AUTO_INCREMENT NOT NULL, eco CHAR(3) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, name VARCHAR(255) NOT NULL, pgn LONGTEXT NOT NULL, uci LONGTEXT CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, epd VARCHAR(92) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, epd_hash BINARY(16) NOT NULL, UNIQUE INDEX uniq_repertoire_opening_epd_hash (epd_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE repertoire_opening');
    }
}
