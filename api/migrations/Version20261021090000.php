<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The language chosen in the SPA (docs/I18N.md): null until the user picks one.
 */
final class Version20261021090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Users: the chosen language (app_user.locale)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD locale VARCHAR(5) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP locale');
    }
}
