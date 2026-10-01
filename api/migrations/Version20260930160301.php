<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The label of a segment when presented in a repertoire test (docs/REPERTOIRE.md § 15), kept like
 * its moves. Presentations logged before (development only) get an empty label.
 */
final class Version20260930160301 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Label of repertoire presentations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE repertoire_presentation ADD label JSON DEFAULT NULL');
        $this->addSql('UPDATE repertoire_presentation SET label = JSON_OBJECT(\'opening\', NULL, \'move\', NULL)');
        $this->addSql('ALTER TABLE repertoire_presentation MODIFY label JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE repertoire_presentation DROP label');
    }
}
