<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Vision (docs/VISION.md, § 2 bis): what a kind keeps besides (vision_question.params: a piece's
 * path, the position it reaches, the squares of a knight's question).
 */
final class Version20261105090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vision: vision_question.params';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vision_question ADD params JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vision_question DROP params');
    }
}
