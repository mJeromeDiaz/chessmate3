<?php

declare(strict_types=1);

namespace App\Command\Gamification;

use App\Gamification\Trophy\TrophyEvaluator;
use App\Gamification\Xp\XpRebuilder;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Recomputes the XP from what was played (docs/GAMIFICATION.md): once after deploying the
 * gamification (past history), and after any change of the rules (XpRules). Idempotent.
 */
#[AsCommand(name: 'app:gamification:rebuild', description: 'Recomputes the XP and the trophies of every user (or one) from what was played')]
final class RebuildCommand
{
    public function __construct(
        private readonly XpRebuilder $rebuilder,
        private readonly TrophyEvaluator $trophies,
        private readonly UserRepository $users,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Option(description: 'Only this user (UUID)')] ?string $user = null): int
    {
        if (null !== $user) {
            $one = Uuid::isValid($user) ? $this->users->find(Uuid::fromString($user)) : null;
            if (null === $one) {
                $io->error('Unknown user.');

                return Command::FAILURE;
            }
            $io->writeln(\sprintf('%d XP, %d trophies.', $this->rebuilder->rebuild($one), $this->trophies->rebuild($one)));

            return Command::SUCCESS;
        }

        $count = 0;
        $xp = 0;
        $won = 0;
        foreach ($this->rebuilder->users() as $one) {
            $xp += $this->rebuilder->rebuild($one);
            $won += $this->trophies->rebuild($one);
            ++$count;
        }
        $io->writeln(\sprintf('%d user(s), %d XP, %d trophies.', $count, $xp, $won));

        return Command::SUCCESS;
    }
}
