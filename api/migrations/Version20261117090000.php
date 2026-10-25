<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The défis' path (docs/DEFIS.md): longer step keys (an opening chapter's carry its repertoire,
 * `op-<base32 id>-boss`), and the reward key of a chest paid once per opening and side.
 */
final class Version20261117090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Défis: mind map step keys up to 40 characters, mind_map_chest.reward_key';
    }

    public function up(Schema $schema): void
    {
        foreach (['mind_map_progress', 'mind_map_attempt', 'mind_map_chest'] as $table) {
            $this->addSql(\sprintf('ALTER TABLE %s MODIFY step VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL', $table));
        }
        $this->addSql('ALTER TABLE mind_map_chest ADD reward_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_mind_map_chest_user_reward ON mind_map_chest (user_id, capacity, reward_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_mind_map_chest_user_reward ON mind_map_chest');
        $this->addSql('ALTER TABLE mind_map_chest DROP reward_key');
        foreach (['mind_map_progress', 'mind_map_attempt', 'mind_map_chest'] as $table) {
            $this->addSql(\sprintf('ALTER TABLE %s MODIFY step VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL', $table));
        }
    }
}
