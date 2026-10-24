<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The wheel ticket's description (docs/SHOP.md, § 4 ter): descriptive rather than explanatory,
 * unless an admin already changed it.
 */
final class Version20261114090000 extends AbstractMigration
{
    private const OLD = 'Un tour de plus à la roue des cadeaux.';
    private const NEW = 'Un tour de roue : 300 cristaux et de superbes cadeaux à gagner.';

    public function getDescription(): string
    {
        return 'Store: the wheel ticket\'s new description';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE shop_offer SET description = ? WHERE slug = 'wheel_ticket' AND description = ?", [self::NEW, self::OLD]);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE shop_offer SET description = ? WHERE slug = 'wheel_ticket' AND description = ?", [self::OLD, self::NEW]);
    }
}
