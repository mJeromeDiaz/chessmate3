<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Account deletion: the email code that confirms it, and the date the frozen account will be purged';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE account_deletion_code (id BINARY(16) NOT NULL, attempts INT NOT NULL, created_at DATETIME NOT NULL, code_hash VARCHAR(64) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, expires_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_account_deletion_code_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE account_deletion_code ADD CONSTRAINT FK_4210D6DBA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE app_user ADD deletion_scheduled_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP deletion_scheduled_at');
        $this->addSql('DROP TABLE account_deletion_code');
    }
}
