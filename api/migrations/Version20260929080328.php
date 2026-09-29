<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Growth history of the light Woodpecker sets (docs/WOODPECKER.md).
 */
final class Version20260929080328 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create woodpecker_set_growth';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE woodpecker_set_growth (id BINARY(16) NOT NULL, round SMALLINT UNSIGNED NOT NULL, added SMALLINT UNSIGNED NOT NULL, puzzle_count SMALLINT UNSIGNED NOT NULL, occurred_at DATETIME NOT NULL, set_id BINARY(16) NOT NULL, INDEX idx_woodpecker_set_growth_set_occurred (set_id, occurred_at), INDEX idx_woodpecker_set_growth_set (set_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE woodpecker_set_growth ADD CONSTRAINT fk_woodpecker_set_growth_set FOREIGN KEY (set_id) REFERENCES woodpecker_set (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE woodpecker_set_growth DROP FOREIGN KEY fk_woodpecker_set_growth_set');
        $this->addSql('DROP TABLE woodpecker_set_growth');
    }
}
