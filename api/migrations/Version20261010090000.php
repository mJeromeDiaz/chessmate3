<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Blindfold: coordinates series';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE blindfold_coordinate_series (id BINARY(16) NOT NULL, orientation VARCHAR(5) NOT NULL, squares JSON NOT NULL, answers JSON NOT NULL, answer_count SMALLINT UNSIGNED NOT NULL, success_count SMALLINT UNSIGNED NOT NULL, answered_ms INT UNSIGNED NOT NULL, validated TINYINT DEFAULT 0 NOT NULL, started_at DATETIME NOT NULL, closed_at DATETIME DEFAULT NULL, user_id BINARY(16) NOT NULL, run_id BINARY(16) NOT NULL, INDEX idx_blindfold_coordinate_series_user_started (user_id, started_at), INDEX idx_blindfold_coordinate_series_user_validated (user_id, orientation, validated), UNIQUE INDEX uniq_blindfold_coordinate_series_run (run_id), INDEX IDX_77950515A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE blindfold_coordinate_series ADD CONSTRAINT FK_77950515A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE blindfold_coordinate_series ADD CONSTRAINT FK_7795051584E3FEC4 FOREIGN KEY (run_id) REFERENCES training_run (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE blindfold_coordinate_series');
    }
}
