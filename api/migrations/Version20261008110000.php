<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Early access: activity log index for the admin statistics across players';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_activity_log_entry_date_user ON activity_log_entry (local_date, user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_activity_log_entry_date_user ON activity_log_entry');
    }
}
