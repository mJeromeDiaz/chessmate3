<?php

declare(strict_types=1);

namespace DoctrineMigrationsCatalog;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Position recall (docs/BLINDFOLD_RECALL.md): the number of pieces of each puzzle's position, as a
 * VIRTUAL generated column (instant: no table rebuild), and its index (built in place, without
 * blocking reads: a few minutes on 1.5M rows, docs/DEPLOY_OVH.md).
 */
final class Version20261027100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Puzzle catalogue: piece count, indexed';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE puzzle
                ADD piece_count SMALLINT UNSIGNED GENERATED ALWAYS AS (CHAR_LENGTH(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(SUBSTRING_INDEX(fen, ' ', 1), '/', ''), '1', ''), '2', ''), '3', ''), '4', ''), '5', ''), '6', ''), '7', ''), '8', ''))) VIRTUAL
            SQL);
        $this->addSql('CREATE INDEX idx_puzzle_pieces ON puzzle (piece_count, selectable, rating, random_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_puzzle_pieces ON puzzle');
        $this->addSql('ALTER TABLE puzzle DROP piece_count');
    }
}
