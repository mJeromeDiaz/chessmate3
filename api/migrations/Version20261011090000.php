<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The coordinates leave the Blindfold domain (docs/COORDINATES.md): their table, indexes and
 * foreign keys take the `coordinates_series` names (the generated ones follow the table name).
 */
final class Version20261011090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Coordinates: blindfold_coordinate_series renamed coordinates_series';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blindfold_coordinate_series DROP FOREIGN KEY FK_77950515A76ED395');
        $this->addSql('ALTER TABLE blindfold_coordinate_series DROP FOREIGN KEY FK_7795051584E3FEC4');
        $this->addSql('RENAME TABLE blindfold_coordinate_series TO coordinates_series');
        $this->addSql('ALTER TABLE coordinates_series
            RENAME INDEX idx_blindfold_coordinate_series_user_started TO idx_coordinates_series_user_started,
            RENAME INDEX idx_blindfold_coordinate_series_user_validated TO idx_coordinates_series_user_validated,
            RENAME INDEX uniq_blindfold_coordinate_series_run TO uniq_coordinates_series_run,
            RENAME INDEX IDX_77950515A76ED395 TO IDX_1FA857E4A76ED395');
        $this->addSql('ALTER TABLE coordinates_series ADD CONSTRAINT FK_1FA857E4A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE coordinates_series ADD CONSTRAINT FK_1FA857E484E3FEC4 FOREIGN KEY (run_id) REFERENCES training_run (id) ON DELETE CASCADE');
        // Stored names of the domain: the source of the series (activity log, exercise XP) and of the
        // validation bonus.
        $this->addSql("UPDATE activity_log_entry SET source_type = 'coordinates_series' WHERE source_type = 'blindfold_coordinate_series'");
        $this->addSql("UPDATE gamification_xp_entry SET source_type = 'coordinates_series' WHERE source_type = 'blindfold_coordinate_series'");
        $this->addSql("UPDATE gamification_xp_entry SET source_type = 'coordinates_validation' WHERE source_type = 'blindfold_validation'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE activity_log_entry SET source_type = 'blindfold_coordinate_series' WHERE source_type = 'coordinates_series'");
        $this->addSql("UPDATE gamification_xp_entry SET source_type = 'blindfold_coordinate_series' WHERE source_type = 'coordinates_series'");
        $this->addSql("UPDATE gamification_xp_entry SET source_type = 'blindfold_validation' WHERE source_type = 'coordinates_validation'");
        $this->addSql('ALTER TABLE coordinates_series DROP FOREIGN KEY FK_1FA857E4A76ED395');
        $this->addSql('ALTER TABLE coordinates_series DROP FOREIGN KEY FK_1FA857E484E3FEC4');
        $this->addSql('ALTER TABLE coordinates_series
            RENAME INDEX idx_coordinates_series_user_started TO idx_blindfold_coordinate_series_user_started,
            RENAME INDEX idx_coordinates_series_user_validated TO idx_blindfold_coordinate_series_user_validated,
            RENAME INDEX uniq_coordinates_series_run TO uniq_blindfold_coordinate_series_run,
            RENAME INDEX IDX_1FA857E4A76ED395 TO IDX_77950515A76ED395');
        $this->addSql('RENAME TABLE coordinates_series TO blindfold_coordinate_series');
        $this->addSql('ALTER TABLE blindfold_coordinate_series ADD CONSTRAINT FK_77950515A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE blindfold_coordinate_series ADD CONSTRAINT FK_7795051584E3FEC4 FOREIGN KEY (run_id) REFERENCES training_run (id) ON DELETE CASCADE');
    }
}
