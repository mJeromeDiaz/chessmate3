<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mind map (docs/MIND_MAP.md, § 8): the questions of the boss fights (mind_map_boss_item).
 */
final class Version20261102090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mind map: mind_map_boss_item';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mind_map_boss_item (id BINARY(16) NOT NULL, boss VARCHAR(16) NOT NULL, question VARCHAR(8) NOT NULL, square VARCHAR(2) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`, fen VARCHAR(92) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`, expected VARCHAR(8) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, status VARCHAR(8) NOT NULL, answer VARCHAR(32) DEFAULT NULL COLLATE `utf8mb4_bin`, started_at DATETIME NOT NULL, submitted_at DATETIME DEFAULT NULL, duration_ms INT UNSIGNED DEFAULT NULL, user_id BINARY(16) NOT NULL, run_id BINARY(16) NOT NULL, INDEX idx_mind_map_boss_item_run (run_id, started_at), INDEX IDX_22572667A76ED395 (user_id), INDEX IDX_2257266784E3FEC4 (run_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE mind_map_boss_item ADD CONSTRAINT FK_22572667A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mind_map_boss_item ADD CONSTRAINT FK_2257266784E3FEC4 FOREIGN KEY (run_id) REFERENCES training_run (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mind_map_boss_item DROP FOREIGN KEY FK_22572667A76ED395');
        $this->addSql('ALTER TABLE mind_map_boss_item DROP FOREIGN KEY FK_2257266784E3FEC4');
        $this->addSql('DROP TABLE mind_map_boss_item');
    }
}
