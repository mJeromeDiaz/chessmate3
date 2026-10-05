<?php

declare(strict_types=1);

namespace App\Ops\Tick;

use App\Ops\Queue\QueueDrainer;
use App\Training\Plan\Reminder\ReminderDispatcher;
use Doctrine\DBAL\Connection;

/**
 * The minute's work of a host without cron-per-minute nor worker (docs/DEPLOY_OVH.md): queue the
 * session reminders now due, then drain the queues (domain events first, then emails, imports and
 * reminders), within a time budget well under PHP's execution limit.
 *
 * One tick at a time: a MySQL named lock (shared by every web server of the host), taken without
 * waiting; a tick that finds it taken does nothing.
 */
final readonly class TickRunner
{
    public const LOCK = 'chessmate_ops_tick';
    /** Under the host's max_execution_time (165 s with PHP-FPM at OVH), and under a minute. */
    public const DRAIN_SECONDS = 50;
    public const MAX_MESSAGES = 1000;
    /** In order of priority. */
    public const TRANSPORTS = ['activity', 'async'];

    public function __construct(
        private ReminderDispatcher $reminders,
        private QueueDrainer $drainer,
        private Connection $connection,
    ) {
    }

    /**
     * @return array{busy: bool, reminders: int, handled: int, failed: int}
     */
    public function run(): array
    {
        // 1: taken; 0: held by another tick; null: an error.
        $acquired = $this->connection->fetchOne('SELECT GET_LOCK(?, 0)', [self::LOCK]);
        if (!is_numeric($acquired) || 1 !== (int) $acquired) {
            return ['busy' => true, 'reminders' => 0, 'handled' => 0, 'failed' => 0];
        }
        try {
            $reminders = $this->reminders->dispatchDue();
            $drained = $this->drainer->drain(self::TRANSPORTS, self::DRAIN_SECONDS, self::MAX_MESSAGES);
        } finally {
            $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }

        return ['busy' => false, 'reminders' => $reminders] + $drained;
    }
}
