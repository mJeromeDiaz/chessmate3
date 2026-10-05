<?php

declare(strict_types=1);

namespace App\Command\E2e;

use App\Entity\User;
use App\Security\Session\AuthenticatedSessionFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Playwright helper (front/tests/e2e): creates a fresh verified user and prints a refresh token for
 * it, so a test starts signed in without going through the email 2FA of the login (covered by the
 * Phase 1 tests). Registered in the "e2e" environment only: it does not exist in dev or prod.
 *
 * --admin gives it ROLE_ADMIN (the administration, docs/EARLY_ACCESS.md); --password sets a
 * password, for a test that signs in through the login page.
 */
#[When(env: 'e2e')]
#[AsCommand(name: 'app:e2e:seed-user', description: 'E2E only: creates a user and prints a refresh token')]
final class SeedCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuthenticatedSessionFactory $sessionFactory,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Give the user ROLE_ADMIN')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Set this password');
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

        $session = $this->sessionFactory->issueFor($user);
        $output->writeln((string) json_encode([
            'id' => $user->getId()->toRfc4122(),
            'email' => $user->getEmail(),
            'refreshToken' => $session->refreshCookie->getValue(),
        ]));

        return Command::SUCCESS;
    }
}
