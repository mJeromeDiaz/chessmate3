<?php

declare(strict_types=1);

namespace App\Repertoire;

use Doctrine\DBAL\Connection;

/**
 * Runs work in a database transaction without EntityManager::wrapInTransaction(), which closes the
 * entity manager on any exception: a refused change (limit reached, stale version...) must leave
 * it usable. The domain's services check everything before their first write, so a refusal leaves
 * the unit of work clean.
 */
final class Transaction
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function run(callable $work): mixed
    {
        $this->connection->beginTransaction();
        try {
            $result = $work();
            $this->connection->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }
}
