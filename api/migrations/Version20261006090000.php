<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Repertoire presentations: the position each segment starts from (replay from the run review)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE repertoire_presentation ADD start_fen VARCHAR(92) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE repertoire_presentation DROP start_fen');
    }
}
