<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260924160611 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE app_user (id BINARY(16) NOT NULL, email VARCHAR(180) DEFAULT NULL, email_verified_at DATETIME DEFAULT NULL, password VARCHAR(255) DEFAULT NULL, roles JSON NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_user_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE audit_log_entry (id BINARY(16) NOT NULL, event_type VARCHAR(64) NOT NULL, ip VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, metadata JSON NOT NULL, created_at DATETIME NOT NULL, user_id BINARY(16) DEFAULT NULL, INDEX idx_audit_user_created (user_id, created_at), INDEX IDX_D2D938A2A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE auth_identity (id BINARY(16) NOT NULL, provider VARCHAR(20) NOT NULL, provider_user_id VARCHAR(255) NOT NULL, provider_email VARCHAR(180) DEFAULT NULL, metadata JSON NOT NULL, access_token_encrypted LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_provider_identity (provider, provider_user_id), INDEX IDX_4E6F6E42A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE trusted_device (id BINARY(16) NOT NULL, token_hash VARCHAR(64) NOT NULL, label VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, last_used_at DATETIME DEFAULT NULL, expires_at DATETIME NOT NULL, revoked_at DATETIME DEFAULT NULL, ip_at_creation VARCHAR(45) DEFAULT NULL, user_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_trusted_device_token_hash (token_hash), INDEX IDX_F37E8F7BA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE audit_log_entry ADD CONSTRAINT FK_D2D938A2A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE auth_identity ADD CONSTRAINT FK_4E6F6E42A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE trusted_device ADD CONSTRAINT FK_F37E8F7BA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');

        // Leftover schema from a previous, unrelated prototype: dropped only where it actually
        // exists, so this migration also runs cleanly against a freshly created (e.g. test) database.
        if ($schema->hasTable('node')) {
            $this->addSql('ALTER TABLE node DROP FOREIGN KEY `FK_857FE8451E61B789`');
            $this->addSql('ALTER TABLE node DROP FOREIGN KEY `FK_857FE845727ACA70`');
            $this->addSql('ALTER TABLE opening_variation DROP FOREIGN KEY `FK_68761124C9B374A4`');
            $this->addSql('ALTER TABLE puzzle DROP FOREIGN KEY `FK_22A6DFDFB3BADC7D`');
            $this->addSql('ALTER TABLE puzzle DROP FOREIGN KEY `FK_22A6DFDFC9B374A4`');
            $this->addSql('ALTER TABLE repertoire DROP FOREIGN KEY `FK_3C367876A76ED395`');
            $this->addSql('DROP TABLE node');
            $this->addSql('DROP TABLE opening_family');
            $this->addSql('DROP TABLE opening_variation');
            $this->addSql('DROP TABLE puzzle');
            $this->addSql('DROP TABLE repertoire');
            $this->addSql('DROP TABLE theme');
            $this->addSql('DROP TABLE user');
        }
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE node (id INT AUTO_INCREMENT NOT NULL, repertoire_id INT NOT NULL, parent_id INT DEFAULT NULL, move VARCHAR(10) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, uci_move VARCHAR(10) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, fen_position LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, move_number INT NOT NULL, is_white_move TINYINT NOT NULL, comment LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, weight INT DEFAULT 1 NOT NULL, is_main_line TINYINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', san VARCHAR(10) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, lan VARCHAR(10) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, before_fen VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, after_fen VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, piece VARCHAR(5) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, flags VARCHAR(10) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, INDEX IDX_857FE8451E61B789727ACA70 (repertoire_id, parent_id), INDEX IDX_857FE84539560607362D30B0 (move_number, is_white_move), INDEX IDX_857FE845727ACA70 (parent_id), INDEX IDX_857FE8451E61B789 (repertoire_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE opening_family (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, label VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, eco_code VARCHAR(3) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, description LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_A1915D325E237E06 (name), UNIQUE INDEX UNIQ_A1915D32EA750E8 (label), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE opening_variation (id INT AUTO_INCREMENT NOT NULL, opening_family_id INT NOT NULL, name VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, label VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, eco_code VARCHAR(3) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, pgn LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, uci LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, epd LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, move_count INT DEFAULT 0 NOT NULL, fen_position LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, details JSON DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_68761124C9B374A4 (opening_family_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE puzzle (id VARCHAR(10) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, opening_family_id INT DEFAULT NULL, opening_variation_id INT DEFAULT NULL, fen LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, moves LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, rating INT NOT NULL, rating_deviation INT NOT NULL, popularity INT NOT NULL, nb_plays INT NOT NULL, themes LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, game_url VARCHAR(500) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, INDEX IDX_22A6DFDFB3BADC7D (opening_variation_id), INDEX IDX_22A6DFDFC9B374A4 (opening_family_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE repertoire (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, is_white TINYINT NOT NULL, description LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, created_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', update_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', user_id INT NOT NULL, INDEX IDX_3C367876A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE theme (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, roles JSON NOT NULL, password VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, lichess_id VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, flag VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, lichess_url VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, google_id INT DEFAULT NULL, is_active TINYINT DEFAULT 0 NOT NULL, activation_token VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, activation_token_expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE node ADD CONSTRAINT `FK_857FE8451E61B789` FOREIGN KEY (repertoire_id) REFERENCES repertoire (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE node ADD CONSTRAINT `FK_857FE845727ACA70` FOREIGN KEY (parent_id) REFERENCES node (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE opening_variation ADD CONSTRAINT `FK_68761124C9B374A4` FOREIGN KEY (opening_family_id) REFERENCES opening_family (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE puzzle ADD CONSTRAINT `FK_22A6DFDFB3BADC7D` FOREIGN KEY (opening_variation_id) REFERENCES opening_variation (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE puzzle ADD CONSTRAINT `FK_22A6DFDFC9B374A4` FOREIGN KEY (opening_family_id) REFERENCES opening_family (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE repertoire ADD CONSTRAINT `FK_3C367876A76ED395` FOREIGN KEY (user_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE audit_log_entry DROP FOREIGN KEY FK_D2D938A2A76ED395');
        $this->addSql('ALTER TABLE auth_identity DROP FOREIGN KEY FK_4E6F6E42A76ED395');
        $this->addSql('ALTER TABLE trusted_device DROP FOREIGN KEY FK_F37E8F7BA76ED395');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE audit_log_entry');
        $this->addSql('DROP TABLE auth_identity');
        $this->addSql('DROP TABLE trusted_device');
    }
}
