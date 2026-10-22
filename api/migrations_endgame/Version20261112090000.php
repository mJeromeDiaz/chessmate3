<?php

declare(strict_types=1);

namespace DoctrineMigrationsEndgame;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The endgame database (docs/ENDGAMES.md, docs/DEPLOY_OVH.md, § 3): the Lichess tablebase answers
 * kept, one row per position.
 */
final class Version20261112090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Endgame database: endgame_tablebase';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE endgame_tablebase (
                fen_hash BINARY(16) NOT NULL,
                fen VARCHAR(92) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`,
                answer JSON NOT NULL,
                fetched_at DATETIME NOT NULL,
                read_on DATE NOT NULL,
                INDEX idx_endgame_tablebase_read (read_on),
                PRIMARY KEY (fen_hash)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE endgame_tablebase');
    }
}
