<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Early access waiting list (docs/EARLY_ACCESS.md): one request per address, and the invitation it led to.
 */
final class Version20261014090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Early access: waiting-list requests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE early_access_request (id BINARY(16) NOT NULL, email VARCHAR(180) NOT NULL, created_at DATETIME NOT NULL, invited_at DATETIME DEFAULT NULL, invitation_id BINARY(16) DEFAULT NULL, UNIQUE INDEX uniq_early_access_request_email (email), INDEX IDX_6771D75AA35D7AF0 (invitation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE early_access_request ADD CONSTRAINT FK_6771D75AA35D7AF0 FOREIGN KEY (invitation_id) REFERENCES early_access_invitation_key (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE early_access_request DROP FOREIGN KEY FK_6771D75AA35D7AF0');
        $this->addSql('DROP TABLE early_access_request');
    }
}
