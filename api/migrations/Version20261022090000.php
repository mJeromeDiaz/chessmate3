<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The language of the early-access emails (docs/I18N.md, § 5): the visitor's on a waiting-list
 * request, carried to the invitation made from it; null on older rows.
 */
final class Version20261022090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Early access: the language of a waiting-list request and of an invitation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE early_access_request ADD locale VARCHAR(5) DEFAULT NULL');
        $this->addSql('ALTER TABLE early_access_invitation_key ADD locale VARCHAR(5) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE early_access_request DROP locale');
        $this->addSql('ALTER TABLE early_access_invitation_key DROP locale');
    }
}
