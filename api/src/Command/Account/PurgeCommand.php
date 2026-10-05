<?php

declare(strict_types=1);

namespace App\Command\Account;

use App\Security\Account\AccountPurger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Purges the accounts whose deletion is due, 30 days after it was confirmed (docs/AUTH.md): run by
 * cron once a day (`15 3 * * * bin/console app:account:purge`). Idempotent.
 */
#[AsCommand(name: 'app:account:purge', description: 'Purges the accounts whose scheduled deletion is due (cron, daily)')]
final class PurgeCommand
{
    public function __construct(private readonly AccountPurger $purger)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $io->writeln(\sprintf('%d account(s) purged.', $this->purger->purgeDue()));

        return Command::SUCCESS;
    }
}
