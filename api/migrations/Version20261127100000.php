<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The arena's own bosses bought in the shop (docs/ARENA.md, § 6 bis): when, and their price.
 */
final class Version20261127100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Arena: bosses bought (arena_unlock.purchased_at, price)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE arena_unlock ADD purchased_at DATETIME DEFAULT NULL, ADD price SMALLINT UNSIGNED DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE arena_unlock DROP purchased_at, DROP price');
    }
}
