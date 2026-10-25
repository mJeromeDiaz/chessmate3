<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The endgames' net (docs/ENDGAMES.md, § 8): whether the player switched it on for a position
 * where it was only offered.
 */
final class Version20261116090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Endgames: the net switched on (endgame_attempt.net_used)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE endgame_attempt ADD net_used TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE endgame_attempt DROP net_used');
    }
}
