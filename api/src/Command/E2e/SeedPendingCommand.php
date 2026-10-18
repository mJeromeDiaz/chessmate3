<?php

declare(strict_types=1);

namespace App\Command\E2e;

use App\Chess\Rules;
use App\Entity\Repertoire\Pending;
use App\Entity\Repertoire\Repertoire;
use App\Enum\Repertoire\PendingReason;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Playwright helper (front/tests/e2e): a pending position in one of the user's repertoires, as an
 * analysis of their games would leave it (docs/REPERTOIRE.md, "En suspens"), without Lichess.
 * Registered in the "e2e" environment only.
 */
#[When(env: 'e2e')]
#[AsCommand(name: 'app:e2e:seed-pending', description: 'E2E only: adds a pending position to a repertoire')]
final class SeedPendingCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('repertoire', InputArgument::REQUIRED, 'The repertoire id')
            ->addArgument('path', InputArgument::REQUIRED, 'The SAN moves leading there, e.g. "e4 c5"');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getArgument('repertoire');
        $repertoire = \is_string($id) && Uuid::isValid($id) ? $this->entityManager->find(Repertoire::class, Uuid::fromString($id)) : null;
        $path = $input->getArgument('path');
        if (null === $repertoire || !\is_string($path)) {
            $output->writeln('<error>Unknown repertoire.</error>');

            return Command::FAILURE;
        }
        $sans = preg_split('/\s+/', trim($path)) ?: [];
        $rules = Rules::initial();
        foreach ($sans as $san) {
            if (null === $rules->playSan($san)) {
                $output->writeln('<error>Illegal move: '.$san.'</error>');

                return Command::FAILURE;
            }
        }
        $pending = new Pending($repertoire, $rules->normalizedFen(), $sans, PendingReason::ReplyUncovered, 3000, 30.0, 48, $this->clock->now());
        $this->entityManager->persist($pending);
        $this->entityManager->flush();
        $output->writeln($pending->getId()->toRfc4122());

        return Command::SUCCESS;
    }
}
