<?php

declare(strict_types=1);

namespace App\Command\Notification;

use Minishlink\WebPush\VAPID;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Generates a VAPID key pair for Web Push (docs/NOTIFICATIONS.md). Run it once per environment:
 * changing the keys invalidates every browser subscription.
 */
#[AsCommand(name: 'app:notification:vapid-keys', description: 'Generates a VAPID key pair for Web Push notifications')]
final class VapidKeysCommand
{
    public function __invoke(SymfonyStyle $io): int
    {
        $keys = VAPID::createVapidKeys();
        foreach (['VAPID_PUBLIC_KEY' => 'publicKey', 'VAPID_PRIVATE_KEY' => 'privateKey'] as $name => $key) {
            $value = $keys[$key] ?? null;
            if (!\is_string($value)) {
                throw new \LogicException('The VAPID keys could not be generated.');
            }
            $io->writeln($name.'='.$value);
        }
        $io->note('The private key is a secret: .env.local in dev, `bin/console secrets:set VAPID_PRIVATE_KEY` in production.');

        return Command::SUCCESS;
    }
}
