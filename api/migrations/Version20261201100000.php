<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * woodpecker_set.opening_slot is never NULL (IFNULL(opening_color, '')) but MySQL made the generated
 * column nullable: declare it NOT NULL, as the mapping does (doctrine:schema:validate in sync).
 */
final class Version20261201100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Woodpecker: woodpecker_set.opening_slot NOT NULL';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE woodpecker_set MODIFY opening_slot VARCHAR(5) GENERATED ALWAYS AS (IFNULL(opening_color, '')) VIRTUAL NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE woodpecker_set MODIFY opening_slot VARCHAR(5) GENERATED ALWAYS AS (IFNULL(opening_color, '')) VIRTUAL");
    }
}
