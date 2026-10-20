<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notation (docs/NOTATION.md): the legendary games' exercises (game_write, game_play), longer kinds.
 */
final class Version20261101090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notation: notation_attempt.kind on 16 characters (legendary games)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notation_attempt CHANGE kind kind VARCHAR(16) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM notation_attempt WHERE kind IN ('game_write', 'game_play')");
        $this->addSql('ALTER TABLE notation_attempt CHANGE kind kind VARCHAR(8) NOT NULL');
    }
}
