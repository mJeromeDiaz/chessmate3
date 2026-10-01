<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Repertoire imports in progress (docs/REPERTOIRE.md, "Import"): PGN text, analysed tree,
 * application request, expiry.
 */
final class Version20260930094630 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create repertoire_import';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE repertoire_import (id BINARY(16) NOT NULL, status VARCHAR(16) NOT NULL, source VARCHAR(8) NOT NULL, label VARCHAR(255) DEFAULT NULL, pgn LONGTEXT DEFAULT NULL, tree LONGTEXT DEFAULT NULL, progress SMALLINT UNSIGNED NOT NULL, error VARCHAR(32) DEFAULT NULL, error_line INT DEFAULT NULL, request JSON DEFAULT NULL, repertoire_id BINARY(16) DEFAULT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, INDEX idx_repertoire_import_user (user_id), INDEX idx_repertoire_import_expires (expires_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE repertoire_import ADD CONSTRAINT FK_9828491CA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE repertoire_import DROP FOREIGN KEY FK_9828491CA76ED395');
        $this->addSql('DROP TABLE repertoire_import');
    }
}
