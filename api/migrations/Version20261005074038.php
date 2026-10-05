<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261005074038 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Private calendar addresses of the users (training_calendar_feed)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE training_calendar_feed (id BINARY(16) NOT NULL, token_hash CHAR(64) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, encrypted_token VARCHAR(255) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, created_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_training_calendar_feed_user (user_id), UNIQUE INDEX uniq_training_calendar_feed_token (token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE training_calendar_feed ADD CONSTRAINT fk_training_calendar_feed_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE training_calendar_feed DROP FOREIGN KEY fk_training_calendar_feed_user');
        $this->addSql('DROP TABLE training_calendar_feed');
    }
}
