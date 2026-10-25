<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The team's announcements (docs/ANNOUNCEMENTS.md): the messages written by an admin, who closed
 * each one and who was emailed, and the account's choice of the announcement emails.
 */
final class Version20261119090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Announcements: announcement, announcement_receipt, app_user.announcement_emails';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE announcement (id BINARY(16) NOT NULL, title_fr VARCHAR(120) NOT NULL, body_fr LONGTEXT NOT NULL, title_en VARCHAR(120) DEFAULT NULL, body_en LONGTEXT DEFAULT NULL, status VARCHAR(12) NOT NULL, starts_at DATETIME DEFAULT NULL, ends_at DATETIME DEFAULT NULL, published_at DATETIME DEFAULT NULL, email_requested_at DATETIME DEFAULT NULL, email_finished_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, author_id BINARY(16) DEFAULT NULL, INDEX idx_announcement_status_starts (status, starts_at), INDEX IDX_4DB9D91CF675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE announcement_receipt (read_at DATETIME DEFAULT NULL, email_queued_at DATETIME DEFAULT NULL, emailed_at DATETIME DEFAULT NULL, announcement_id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, INDEX idx_announcement_receipt_user (user_id), INDEX IDX_AF38EB8F913AEA17 (announcement_id), PRIMARY KEY (announcement_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE announcement ADD CONSTRAINT FK_4DB9D91CF675F31B FOREIGN KEY (author_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE announcement_receipt ADD CONSTRAINT FK_AF38EB8F913AEA17 FOREIGN KEY (announcement_id) REFERENCES announcement (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE announcement_receipt ADD CONSTRAINT FK_AF38EB8FA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE app_user ADD announcement_emails TINYINT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE announcement DROP FOREIGN KEY FK_4DB9D91CF675F31B');
        $this->addSql('ALTER TABLE announcement_receipt DROP FOREIGN KEY FK_AF38EB8F913AEA17');
        $this->addSql('ALTER TABLE announcement_receipt DROP FOREIGN KEY FK_AF38EB8FA76ED395');
        $this->addSql('DROP TABLE announcement_receipt');
        $this->addSql('DROP TABLE announcement');
        $this->addSql('ALTER TABLE app_user DROP announcement_emails');
    }
}
