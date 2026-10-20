<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mind map paths (docs/MIND_MAP.md): the progress by step, the runs played for a step, the chests.
 */
final class Version20261028090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mind map paths: mind_map_progress, mind_map_attempt, mind_map_chest';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mind_map_progress (id BINARY(16) NOT NULL, capacity VARCHAR(16) NOT NULL, step VARCHAR(24) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, passes SMALLINT UNSIGNED DEFAULT 0 NOT NULL, completed_at DATETIME DEFAULT NULL, skipped_at DATETIME DEFAULT NULL, updated_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_mind_map_progress_user_step (user_id, capacity, step), INDEX IDX_636E3B16A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE mind_map_progress ADD CONSTRAINT FK_636E3B16A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('CREATE TABLE mind_map_attempt (id BINARY(16) NOT NULL, capacity VARCHAR(16) NOT NULL, step VARCHAR(24) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, outcome VARCHAR(8) NOT NULL, item_count SMALLINT UNSIGNED DEFAULT 0 NOT NULL, success_count SMALLINT UNSIGNED DEFAULT 0 NOT NULL, completed TINYINT DEFAULT 0 NOT NULL, started_at DATETIME NOT NULL, judged_at DATETIME DEFAULT NULL, user_id BINARY(16) NOT NULL, run_id BINARY(16) NOT NULL, INDEX idx_mind_map_attempt_user_step (user_id, capacity, step), UNIQUE INDEX uniq_mind_map_attempt_run (run_id), INDEX IDX_735F32CDA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE mind_map_attempt ADD CONSTRAINT FK_735F32CDA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mind_map_attempt ADD CONSTRAINT FK_735F32CD84E3FEC4 FOREIGN KEY (run_id) REFERENCES training_run (id) ON DELETE CASCADE');
        $this->addSql('CREATE TABLE mind_map_chest (id BINARY(16) NOT NULL, capacity VARCHAR(16) NOT NULL, step VARCHAR(24) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, crystals INT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, opened_at DATETIME DEFAULT NULL, user_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_mind_map_chest_user_step (user_id, capacity, step), INDEX idx_mind_map_chest_user_opened (user_id, opened_at), INDEX IDX_9730391FA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE mind_map_chest ADD CONSTRAINT FK_9730391FA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mind_map_progress DROP FOREIGN KEY FK_636E3B16A76ED395');
        $this->addSql('ALTER TABLE mind_map_attempt DROP FOREIGN KEY FK_735F32CDA76ED395');
        $this->addSql('ALTER TABLE mind_map_attempt DROP FOREIGN KEY FK_735F32CD84E3FEC4');
        $this->addSql('ALTER TABLE mind_map_chest DROP FOREIGN KEY FK_9730391FA76ED395');
        $this->addSql('DROP TABLE mind_map_progress');
        $this->addSql('DROP TABLE mind_map_attempt');
        $this->addSql('DROP TABLE mind_map_chest');
    }
}
