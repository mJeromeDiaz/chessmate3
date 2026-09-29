<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Append-only activity log (docs/ACTIVITY.md).
 */
final class Version20260928161532 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create activity_log_entry';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE activity_log_entry (id BINARY(16) NOT NULL, exercise_type VARCHAR(32) NOT NULL, success TINYINT NOT NULL, duration_ms INT UNSIGNED NOT NULL, item_count SMALLINT UNSIGNED NOT NULL, source_type VARCHAR(32) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, source_id VARCHAR(64) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, occurred_at DATETIME NOT NULL, local_date DATE NOT NULL, timezone VARCHAR(64) NOT NULL, metadata JSON NOT NULL, user_id BINARY(16) NOT NULL, INDEX idx_activity_log_entry_user_date (user_id, local_date), INDEX idx_activity_log_entry_user_type_date (user_id, exercise_type, local_date), INDEX idx_activity_log_entry_user (user_id), UNIQUE INDEX uniq_activity_log_entry_source (source_type, source_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE activity_log_entry ADD CONSTRAINT fk_activity_log_entry_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE activity_log_entry DROP FOREIGN KEY fk_activity_log_entry_user');
        $this->addSql('DROP TABLE activity_log_entry');
    }
}
