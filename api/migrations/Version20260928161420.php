<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Messenger's table (async emails since Phase 1, and the domain-event outbox of Phase 4). It was
 * created by hand in some databases (auto_setup=0), hence the guard.
 */
final class Version20260928161420 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create messenger_messages if missing';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('messenger_messages')) {
            return;
        }

        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messenger_messages');
    }
}
