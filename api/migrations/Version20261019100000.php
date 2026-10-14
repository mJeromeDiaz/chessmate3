<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Daily streak freezes (docs/SHOP.md): the days a freeze covered.
 */
final class Version20261019100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Streak freezes: gamification_frozen_day';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE gamification_frozen_day (local_date DATE NOT NULL, created_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, PRIMARY KEY (user_id, local_date)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE gamification_frozen_day ADD CONSTRAINT FK_D879A87A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gamification_frozen_day DROP FOREIGN KEY FK_D879A87A76ED395');
        $this->addSql('DROP TABLE gamification_frozen_day');
    }
}
