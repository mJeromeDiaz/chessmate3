<?php

declare(strict_types=1);

namespace DoctrineMigrationsCatalog;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Opening sets of the tailored session (docs/COACH.md): each puzzle's opening family and the side
 * its player plays, as VIRTUAL generated columns (instant: no table rebuild), and their index (built
 * in place, without blocking reads: a few minutes on 1.5M rows, docs/DEPLOY_OVH.md).
 */
final class Version20261016100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Puzzle catalogue: opening family and solver color, indexed';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE puzzle
                ADD opening_family VARCHAR(64) CHARACTER SET ascii COLLATE `ascii_bin` GENERATED ALWAYS AS (CONVERT(JSON_UNQUOTE(JSON_EXTRACT(opening_tags, '$[0]')) USING ascii)) VIRTUAL,
                ADD solver_color CHAR(1) CHARACTER SET ascii COLLATE `ascii_bin` GENERATED ALWAYS AS (IF(SUBSTRING(fen, LOCATE(' ', fen) + 1, 1) = 'w', 'b', 'w')) VIRTUAL
            SQL);
        $this->addSql('CREATE INDEX idx_puzzle_opening ON puzzle (opening_family, solver_color, selectable, rating, random_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_puzzle_opening ON puzzle');
        $this->addSql('ALTER TABLE puzzle DROP opening_family, DROP solver_color');
    }
}
