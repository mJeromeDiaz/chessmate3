<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notation (docs/NOTATION.md): the line exercise, from the player's repertoires.
 */
final class Version20261031090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notation: repertoire lines (notation_attempt.shown, line_key, label, orientation)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notation_attempt ADD shown JSON DEFAULT NULL, ADD line_key VARCHAR(36) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`, ADD label VARCHAR(255) DEFAULT NULL, ADD orientation VARCHAR(5) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notation_attempt DROP shown, DROP line_key, DROP label, DROP orientation');
    }
}
