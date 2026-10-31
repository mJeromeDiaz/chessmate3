<?php

declare(strict_types=1);

namespace App\Guest;

use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Training\Module;
use App\MindMap\Path\Chapter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * What a guest may play (docs/GUEST.md), past the API's whitelist (config/packages/security.yaml):
 * the free modules on their own, any module as a step of a path's first chapter, never a session;
 * and a few endgame positions a day, each one costing calls to the Lichess tablebase. A real
 * account passes every check.
 */
final readonly class GuestPolicy
{
    /** The modules a guest plays on their own page. */
    public const OPEN_MODULES = [
        Module::Puzzles,
        Module::PuzzleRush,
        Module::PuzzleStreak,
        Module::Brilliancy,
        Module::Coordinates,
        Module::Notation,
        Module::Blindfold,
        Module::BlindfoldRecall,
        Module::Evaluation,
        Module::Endgames,
    ];

    /** Endgame positions served to a guest per local day, the module and the paths together. */
    public const ENDGAME_POSITIONS_PER_DAY = 3;

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    /**
     * A run about to start.
     *
     * @param Uuid|null   $parentId the session the run is a step of
     * @param string|null $pathStep the path step the run is played for (its chapter checked by {@see assertChapter()})
     *
     * @throws GuestRefusedException
     */
    public function assertRun(User $user, Module $module, ?Uuid $parentId, ?string $pathStep): void
    {
        if (!$user->isGuest()) {
            return;
        }
        if (null !== $parentId || (null === $pathStep && !\in_array($module, self::OPEN_MODULES, true))) {
            throw new GuestRefusedException(GuestRefusedException::ACCOUNT_REQUIRED);
        }
        if (Module::Endgames === $module && $this->endgamePositionsLeft($user) <= 0) {
            throw new GuestRefusedException(GuestRefusedException::ENDGAME_QUOTA);
        }
    }

    /**
     * A path step about to be played or skipped: the first chapter of each path only, as a demo.
     *
     * @throws GuestRefusedException
     */
    public function assertChapter(User $user, Chapter $chapter): void
    {
        if ($user->isGuest() && 1 !== $chapter->number) {
            throw new GuestRefusedException(GuestRefusedException::ACCOUNT_REQUIRED);
        }
    }

    /**
     * The tablebase asked by the board (GET /endgames/tablebase): a guest only while an endgame run
     * is open, each answer possibly a call to Lichess.
     *
     * @param Run|null $active the player's open run
     *
     * @throws GuestRefusedException
     */
    public function assertTablebase(User $user, ?Run $active): void
    {
        if ($user->isGuest() && Module::Endgames !== $active?->getModule()) {
            throw new GuestRefusedException(GuestRefusedException::ACCOUNT_REQUIRED);
        }
    }

    /**
     * The endgame positions a guest may still be served today (its local day); unlimited (PHP_INT_MAX)
     * for an account.
     */
    public function endgamePositionsLeft(User $user): int
    {
        if (!$user->isGuest()) {
            return \PHP_INT_MAX;
        }
        $midnight = $this->clock->now()->setTimezone($user->getDateTimeZone())->setTime(0, 0);
        $served = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM endgame_attempt WHERE user_id = ? AND served_at >= ?',
            [$user->getId()->toBinary(), $midnight->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
            [ParameterType::BINARY, ParameterType::STRING],
        );

        return max(0, self::ENDGAME_POSITIONS_PER_DAY - (is_numeric($served) ? (int) $served : 0));
    }
}
