<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gamification: the trophies won, at the date of the feat';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE gamification_trophy (id BINARY(16) NOT NULL, trophy VARCHAR(32) NOT NULL, unlocked_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_gamification_trophy_user_trophy (user_id, trophy), INDEX IDX_64049640A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE gamification_trophy ADD CONSTRAINT FK_64049640A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE gamification_trophy');
    }
}
