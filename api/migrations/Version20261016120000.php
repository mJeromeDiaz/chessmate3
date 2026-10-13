<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tailored session (docs/COACH.md): a saved session the app keeps up to date has an origin
 * ("coach"), one per user and origin (NULL for the user's own plans: not unique).
 */
final class Version20261016120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Training: origin of the saved sessions kept up to date by the app';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE training_session_plan ADD origin VARCHAR(16) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_training_session_plan_origin ON training_session_plan (user_id, origin)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_training_session_plan_origin ON training_session_plan');
        $this->addSql('ALTER TABLE training_session_plan DROP origin');
    }
}
