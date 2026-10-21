<?php

declare(strict_types=1);

namespace App\Command\E2e;

use App\Entity\Catalog\Puzzle;
use App\Entity\MindMap\Progress;
use App\Entity\User;
use App\Enum\MindMap\Capacity;
use App\Enum\MindMap\StepKind;
use App\MindMap\Path\Paths;
use App\Puzzle\Selection\SelectionRebuilder;
use App\Repository\Catalog\PuzzleRepository;
use App\Security\Session\AuthenticatedSessionFactory;
use App\Shop\Wallet\CrownAdjustment;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Playwright helper (front/tests/e2e): creates a fresh verified user and prints a refresh token for
 * it, so a test starts signed in without going through the email 2FA of the login (covered by the
 * Phase 1 tests). Registered in the "e2e" environment only: it does not exist in dev or prod.
 *
 * --admin gives it ROLE_ADMIN (the administration, docs/EARLY_ACCESS.md); --password sets a
 * password, for a test that signs in through the login page; --crowns gives it crowns of the
 * store (docs/SHOP.md), as an admin's grant; --mind-map-chapters completes the first chapters of
 * the visualisation path (docs/MIND_MAP.md), every step but the hidden bosses, without rewards;
 * --mind-map-steps completes the steps named; --mind-map-capacity picks the path (visualisation by
 * default, or calculation); --engine-puzzles adds to the catalogue, if missing, puzzles of the
 * calculation path's chapter 3 (docs/CALCULATION.md, § 2 quater), few among the samples.
 */
#[When(env: 'e2e')]
#[AsCommand(name: 'app:e2e:seed-user', description: 'E2E only: creates a user and prints a refresh token')]
final class SeedCommand extends Command
{
    /**
     * Chapter 3's puzzles, at a new player's level (1500 − 150): the opponent has only two replies
     * to Rd8+ (3.1, 3.2); after a8e8, black threatens Re1# (3.3); Rxd7 is met by Qb1+ (3.4).
     *
     * @var list<array{string, string, string, list<string>}>
     */
    private const ENGINE_PUZZLES = [
        ['E2EK1', '6k1/p4pp1/7p/8/8/8/4rPPP/3R2K1 b - - 0 1', 'a7a6 d1d8 e2e8 d8e8', ['short']],
        ['E2EK2', 'r5k1/5ppp/8/8/8/8/5PPP/6K1 b - - 0 1', 'a8e8 h2h3', ['defensiveMove', 'oneMove']],
        ['E2EK3', '6k1/3r1ppp/1q6/8/8/8/5PPP/3R2K1 b - - 0 1', 'h7h6 d1d7 b6b1 d7d1', ['short']],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuthenticatedSessionFactory $sessionFactory,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly CrownAdjustment $crowns,
        private readonly PuzzleRepository $puzzles,
        private readonly SelectionRebuilder $rebuilder,
        #[Autowire(service: 'doctrine.orm.catalog_entity_manager')]
        private readonly EntityManagerInterface $catalog,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Give the user ROLE_ADMIN')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Set this password')
            ->addOption('crowns', null, InputOption::VALUE_REQUIRED, 'Give it this many crowns')
            ->addOption('mind-map-chapters', null, InputOption::VALUE_REQUIRED, 'Complete this many chapters of the visualisation path')
            ->addOption('mind-map-steps', null, InputOption::VALUE_REQUIRED, 'Complete these steps of the visualisation path (keys, comma-separated)')
            ->addOption('mind-map-capacity', null, InputOption::VALUE_REQUIRED, 'The path of --mind-map-chapters and --mind-map-steps', Capacity::Visualisation->value)
            ->addOption('engine-puzzles', null, InputOption::VALUE_NONE, 'Add the puzzles of the calculation path\'s chapter 3 to the catalogue');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $user = new User();
        $user->setEmail(sprintf('e2e-%s@example.com', bin2hex(random_bytes(6))));
        $user->markEmailVerified();
        if (true === $input->getOption('admin')) {
            $user->setRoles(['ROLE_ADMIN']);
        }
        $password = $input->getOption('password');
        if (\is_string($password) && '' !== $password) {
            $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        }
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $crowns = $input->getOption('crowns');
        if (is_numeric($crowns) && (int) $crowns > 0) {
            $this->crowns->adjust($user->getId(), (int) $crowns, 'e2e', null);
        }

        $capacity = $input->getOption('mind-map-capacity');
        $capacity = (\is_string($capacity) ? Capacity::tryFrom($capacity) : null) ?? Capacity::Visualisation;
        $steps = [];
        $chapters = $input->getOption('mind-map-chapters');
        if (is_numeric($chapters) && (int) $chapters > 0) {
            foreach (\array_slice(Paths::chapters($capacity), 0, (int) $chapters) as $chapter) {
                foreach ($chapter->allSteps() as $step) {
                    if (StepKind::HiddenBoss !== $step->kind) {
                        $steps[$step->key] = $step;
                    }
                }
            }
        }
        $named = $input->getOption('mind-map-steps');
        foreach (\is_string($named) && '' !== $named ? explode(',', $named) : [] as $key) {
            $step = Paths::find($capacity, trim($key))['step'] ?? null;
            if (null !== $step) {
                $steps[$step->key] = $step;
            }
        }
        $now = new \DateTimeImmutable();
        foreach ($steps as $step) {
            $progress = new Progress($user, $capacity, $step->key, $now);
            for ($i = 0; $i < max(1, $step->passes); ++$i) {
                $progress->pass($step->passes, $now);
            }
            $this->entityManager->persist($progress);
        }
        $this->entityManager->flush();

        if (true === $input->getOption('engine-puzzles')) {
            $this->addEnginePuzzles();
        }

        $session = $this->sessionFactory->issueFor($user);
        $output->writeln((string) json_encode([
            'id' => $user->getId()->toRfc4122(),
            'email' => $user->getEmail(),
            'refreshToken' => $session->refreshCookie->getValue(),
        ]));

        return Command::SUCCESS;
    }

    private function addEnginePuzzles(): void
    {
        $added = [];
        foreach (self::ENGINE_PUZZLES as [$lichessId, $fen, $moves, $themes]) {
            if (null === $this->puzzles->findOneByLichessId($lichessId)) {
                $added[] = $puzzle = new Puzzle($lichessId, $fen, $moves, 1350, 80, 95, 5000, $themes, 'https://lichess.org/training/'.$lichessId, null);
                $this->catalog->persist($puzzle);
            }
        }
        $this->catalog->flush();
        $this->rebuilder->addPuzzles(array_map(static fn (Puzzle $puzzle): int => (int) $puzzle->getId(), $added));
        $this->catalog->clear();
    }
}
