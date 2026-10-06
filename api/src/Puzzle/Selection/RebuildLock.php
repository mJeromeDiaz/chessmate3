<?php

declare(strict_types=1);

namespace App\Puzzle\Selection;

use Doctrine\DBAL\Connection;

/**
 * MySQL named lock held by {@see SelectionRebuilder::rebuildAll()} while `puzzle_theme_membership`
 * is rebuilt in place (empty, then partial, without its primary key): the themed draw checks it and
 * refuses rather than scanning a half-built table. Named after the catalogue's database, so the
 * dev, test and e2e databases of one server never share it. Released by MySQL if the connection
 * drops.
 */
final class RebuildLock
{
    private const NAME = "CONCAT('dontstayrooky.selection_rebuild.', DATABASE())";

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return bool false when another rebuild holds it
     */
    public function acquire(): bool
    {
        return 1 === $this->value('SELECT GET_LOCK('.self::NAME.', 0)');
    }

    public function release(): void
    {
        $this->connection->fetchOne('SELECT RELEASE_LOCK('.self::NAME.')');
    }

    /**
     * Held by any connection, this one included.
     */
    public function isHeld(): bool
    {
        return null !== $this->connection->fetchOne('SELECT IS_USED_LOCK('.self::NAME.')');
    }

    private function value(string $sql): int
    {
        $value = $this->connection->fetchOne($sql);

        return is_numeric($value) ? (int) $value : 0;
    }
}
