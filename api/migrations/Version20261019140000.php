<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The blindfold pardon's description, as the pardon works (docs/BLINDFOLD.md, § 3 bis): changed
 * only where an admin left the installed one.
 */
final class Version20261019140000 extends AbstractMigration
{
    private const BEFORE = 'Un coup faux à l’aveugle : tu rejoues au lieu de le compter.';
    private const AFTER = 'Un puzzle à l’aveugle : tes erreurs effacées, ou le raté compté comme résolu.';

    public function getDescription(): string
    {
        return 'Store: the blindfold pardon offer described as it works';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE shop_offer SET description = ? WHERE slug = 'blindfold_pardon' AND description = ?", [self::AFTER, self::BEFORE]);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE shop_offer SET description = ? WHERE slug = 'blindfold_pardon' AND description = ?", [self::BEFORE, self::AFTER]);
    }
}
