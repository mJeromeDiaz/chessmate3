<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Early access: an OAuth sign-up carries the ticket of its invitation key';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oauth_flow ADD registration_ticket VARCHAR(64) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oauth_flow DROP registration_ticket');
    }
}
