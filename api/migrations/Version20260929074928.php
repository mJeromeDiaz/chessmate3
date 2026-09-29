<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Woodpecker modes (docs/WOODPECKER.md): every existing set becomes "classic". The schedule
 * columns become nullable (light sets have none) under a CHECK constraint tying them to the mode;
 * a round's duration and deadline become nullable; one ongoing set per user and per mode.
 */
final class Version20260929074928 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add woodpecker_set.mode (existing sets: classic), nullable schedule, one ongoing set per mode';
    }

    public function up(Schema $schema): void
    {
        // The default fills the existing rows, then goes: the application always sets the mode.
        $this->addSql("ALTER TABLE woodpecker_set ADD mode VARCHAR(8) NOT NULL DEFAULT 'classic' AFTER name");
        $this->addSql('ALTER TABLE woodpecker_set ALTER mode DROP DEFAULT');
        $this->addSql('ALTER TABLE woodpecker_set CHANGE cycle_count cycle_count SMALLINT UNSIGNED DEFAULT NULL, CHANGE first_cycle_days first_cycle_days SMALLINT UNSIGNED DEFAULT NULL, CHANGE reduction_factor reduction_factor DOUBLE PRECISION DEFAULT NULL, CHANGE min_cycle_days min_cycle_days SMALLINT UNSIGNED DEFAULT NULL, CHANGE rest_days rest_days SMALLINT UNSIGNED DEFAULT NULL');
        $this->addSql("ALTER TABLE woodpecker_set ADD CONSTRAINT chk_woodpecker_set_mode_config CHECK ((mode = 'classic' AND cycle_count IS NOT NULL AND first_cycle_days IS NOT NULL AND reduction_factor IS NOT NULL AND min_cycle_days IS NOT NULL AND rest_days IS NOT NULL) OR (mode = 'light' AND cycle_count IS NULL AND first_cycle_days IS NULL AND reduction_factor IS NULL AND min_cycle_days IS NULL AND rest_days IS NULL))");
        $this->addSql('DROP INDEX uniq_woodpecker_set_active_user ON woodpecker_set');
        $this->addSql('CREATE UNIQUE INDEX uniq_woodpecker_set_active_user_mode ON woodpecker_set (active_user_id, mode)');
        $this->addSql('ALTER TABLE woodpecker_cycle CHANGE duration_days duration_days SMALLINT UNSIGNED DEFAULT NULL, CHANGE deadline_at deadline_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $light = $this->connection->fetchOne("SELECT COUNT(*) FROM woodpecker_set WHERE mode = 'light'");
        $this->abortIf(is_numeric($light) && (int) $light > 0, 'Light sets exist: they cannot be represented without a mode.');

        $this->addSql('ALTER TABLE woodpecker_cycle CHANGE duration_days duration_days SMALLINT UNSIGNED NOT NULL, CHANGE deadline_at deadline_at DATETIME NOT NULL');
        $this->addSql('DROP INDEX uniq_woodpecker_set_active_user_mode ON woodpecker_set');
        $this->addSql('CREATE UNIQUE INDEX uniq_woodpecker_set_active_user ON woodpecker_set (active_user_id)');
        $this->addSql('ALTER TABLE woodpecker_set DROP CHECK chk_woodpecker_set_mode_config');
        $this->addSql('ALTER TABLE woodpecker_set CHANGE cycle_count cycle_count SMALLINT UNSIGNED NOT NULL, CHANGE first_cycle_days first_cycle_days SMALLINT UNSIGNED NOT NULL, CHANGE reduction_factor reduction_factor DOUBLE PRECISION NOT NULL, CHANGE min_cycle_days min_cycle_days SMALLINT UNSIGNED NOT NULL, CHANGE rest_days rest_days SMALLINT UNSIGNED NOT NULL');
        $this->addSql('ALTER TABLE woodpecker_set DROP mode');
    }
}
