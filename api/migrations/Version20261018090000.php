<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Guided tour (docs/ONBOARDING.md): the declared level and goal of each player.
 */
final class Version20261018090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Guided tour: onboarding_answer';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE onboarding_answer (level VARCHAR(16) DEFAULT NULL, goal VARCHAR(16) DEFAULT NULL, updated_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, PRIMARY KEY (user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE onboarding_answer ADD CONSTRAINT fk_onboarding_answer_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE onboarding_answer DROP FOREIGN KEY fk_onboarding_answer_user');
        $this->addSql('DROP TABLE onboarding_answer');
    }
}
