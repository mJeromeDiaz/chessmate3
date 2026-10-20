<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Coordinates modes (docs/COORDINATES.md, § 2 bis): find (every series so far), name, color.
 */
final class Version20261029090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Coordinates modes: coordinates_series.mode';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE coordinates_series ADD mode VARCHAR(8) DEFAULT 'find' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coordinates_series DROP mode');
    }
}
