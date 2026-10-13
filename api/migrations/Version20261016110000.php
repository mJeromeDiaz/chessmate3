<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Opening sets of the tailored session (docs/COACH.md): light sets drawing from an opening family,
 * one ongoing per colour on top of the one ongoing set per mode (the unique index gains the
 * generated opening_slot: the colour, '' for the other sets).
 */
final class Version20261016110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Woodpecker: opening sets (family, colour, both colours), one ongoing per colour';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE woodpecker_set
                ADD opening_family VARCHAR(64) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`,
                ADD opening_color VARCHAR(5) DEFAULT NULL,
                ADD opening_both_colors TINYINT DEFAULT 0 NOT NULL,
                ADD opening_slot VARCHAR(5) GENERATED ALWAYS AS (IFNULL(opening_color, '')) VIRTUAL
            SQL);
        $this->addSql('DROP INDEX uniq_woodpecker_set_active_user_mode ON woodpecker_set');
        $this->addSql('CREATE UNIQUE INDEX uniq_woodpecker_set_active_user_mode ON woodpecker_set (active_user_id, mode, opening_slot)');
        $this->addSql("ALTER TABLE woodpecker_set ADD CONSTRAINT chk_woodpecker_set_opening CHECK (opening_color IS NULL OR (mode = 'light' AND opening_family IS NOT NULL))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE woodpecker_set DROP CHECK chk_woodpecker_set_opening');
        $this->addSql('DROP INDEX uniq_woodpecker_set_active_user_mode ON woodpecker_set');
        $this->addSql('CREATE UNIQUE INDEX uniq_woodpecker_set_active_user_mode ON woodpecker_set (active_user_id, mode)');
        $this->addSql('ALTER TABLE woodpecker_set DROP opening_slot, DROP opening_family, DROP opening_color, DROP opening_both_colors');
    }
}
