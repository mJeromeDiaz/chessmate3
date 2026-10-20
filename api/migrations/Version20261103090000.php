<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mind map (docs/MIND_MAP.md, § 4): the step a run is played for (training_run.path_step), set by
 * the server only; what a module counts as a boss's failures (mind_map_attempt.failures).
 */
final class Version20261103090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Training: training_run.path_step; mind map: mind_map_attempt.failures';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE training_run ADD path_step VARCHAR(48) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`');
        $this->addSql('ALTER TABLE mind_map_attempt ADD failures SMALLINT UNSIGNED DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE training_run DROP path_step');
        $this->addSql('ALTER TABLE mind_map_attempt DROP failures');
    }
}
