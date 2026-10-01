<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MySQL table of the repertoire.explorer_cache pool (Symfony DoctrineDbalAdapter): Lichess explorer
 * and cloud eval answers, docs/REPERTOIRE.md.
 */
final class Version20260929152827 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create repertoire_explorer_cache';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE repertoire_explorer_cache (item_id VARBINARY(255) NOT NULL, item_data MEDIUMBLOB NOT NULL, item_lifetime INT UNSIGNED DEFAULT NULL, item_time INT UNSIGNED NOT NULL, PRIMARY KEY (item_id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE repertoire_explorer_cache');
    }
}
