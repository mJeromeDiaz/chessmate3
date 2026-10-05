<?php

declare(strict_types=1);

namespace App\Command\Ops;

use App\Ops\Check\DeploymentChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The deployment checks from the command line (docs/DEPLOY_OVH.md). Run after each deployment;
 * the web's view (`GET /api/ops/check`) can differ on a shared host.
 */
#[AsCommand(name: 'app:deploy:check', description: 'Checks that this host can run the application (PHP, extensions, MySQL, secrets, settings)')]
final class DeployCheckCommand
{
    public function __construct(private readonly DeploymentChecker $checker)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Option(description: 'Also try an outgoing HTTPS connection')] bool $network = false): int
    {
        $checks = $this->checker->run($network);
        $io->table(['Check', 'Level', 'Detail'], array_map(static fn (array $check): array => [$check['name'], strtoupper($check['level']), $check['detail']], $checks));

        if (DeploymentChecker::hasErrors($checks)) {
            $io->error('This host cannot run the application as it is.');

            return Command::FAILURE;
        }
        $io->success('Ready (review the warnings for production).');

        return Command::SUCCESS;
    }
}
