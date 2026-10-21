<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Calculation (docs/CALCULATION.md, § 2 bis): a question on the position a line reaches.
 */
final class Version20261109090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Calculation: calculation_attempt.ask, target';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calculation_attempt ADD ask VARCHAR(8) DEFAULT NULL, ADD target VARCHAR(2) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calculation_attempt DROP ask, DROP target');
    }
}
