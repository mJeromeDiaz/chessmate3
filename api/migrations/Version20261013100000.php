<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Position evaluation (docs/EVALUATION.md): positions are entered by admins, no longer loaded from
 * a file: no file key, a unique FEN, optional plan, tip and tag, creation and change dates.
 */
final class Version20261013100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Evaluation: positions entered by admins';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_evaluation_position_key ON evaluation_position');
        // Rows loaded from the former file, if any, get today as their dates.
        $this->addSql('ALTER TABLE evaluation_position ADD created_at DATETIME DEFAULT NULL, ADD updated_at DATETIME DEFAULT NULL, DROP position_key, CHANGE plan plan VARCHAR(16) DEFAULT NULL, CHANGE tip tip VARCHAR(255) DEFAULT NULL, CHANGE tag tag VARCHAR(12) DEFAULT NULL');
        $this->addSql('UPDATE evaluation_position SET created_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()');
        $this->addSql('ALTER TABLE evaluation_position CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_evaluation_position_fen ON evaluation_position (fen)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_evaluation_position_fen ON evaluation_position');
        $this->addSql('ALTER TABLE evaluation_position ADD position_key VARCHAR(64) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`, DROP created_at, DROP updated_at, CHANGE plan plan VARCHAR(16) NOT NULL, CHANGE tip tip VARCHAR(255) NOT NULL, CHANGE tag tag VARCHAR(12) NOT NULL');
        $this->addSql("UPDATE evaluation_position SET position_key = LOWER(HEX(id))");
        $this->addSql('ALTER TABLE evaluation_position CHANGE position_key position_key VARCHAR(64) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`');
        $this->addSql('CREATE UNIQUE INDEX uniq_evaluation_position_key ON evaluation_position (position_key)');
    }
}
