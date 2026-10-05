<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Puzzle catalogue apart (docs/DEPLOY_OVH.md, § 3): attempts and Woodpecker sets keep puzzle ids without foreign keys; attempts copy the puzzle themes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle_attempt DROP FOREIGN KEY fk_puzzle_attempt_puzzle');
        $this->addSql('ALTER TABLE woodpecker_attempt DROP FOREIGN KEY fk_woodpecker_attempt_puzzle');
        $this->addSql('ALTER TABLE woodpecker_set_puzzle DROP FOREIGN KEY fk_woodpecker_set_puzzle_puzzle');

        // Filled from the catalogue while it is still in this database, then mandatory.
        $this->addSql('ALTER TABLE puzzle_attempt ADD puzzle_themes JSON DEFAULT NULL');
        $this->addSql('UPDATE puzzle_attempt a JOIN puzzle p ON p.id = a.puzzle_id SET a.puzzle_themes = p.themes');
        $this->addSql('UPDATE puzzle_attempt SET puzzle_themes = JSON_ARRAY() WHERE puzzle_themes IS NULL');
        $this->addSql('ALTER TABLE puzzle_attempt MODIFY puzzle_themes JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle_attempt DROP puzzle_themes');
        $this->addSql('ALTER TABLE puzzle_attempt ADD CONSTRAINT fk_puzzle_attempt_puzzle FOREIGN KEY (puzzle_id) REFERENCES puzzle (id)');
        $this->addSql('ALTER TABLE woodpecker_attempt ADD CONSTRAINT fk_woodpecker_attempt_puzzle FOREIGN KEY (puzzle_id) REFERENCES puzzle (id)');
        $this->addSql('ALTER TABLE woodpecker_set_puzzle ADD CONSTRAINT fk_woodpecker_set_puzzle_puzzle FOREIGN KEY (puzzle_id) REFERENCES puzzle (id)');
    }
}
