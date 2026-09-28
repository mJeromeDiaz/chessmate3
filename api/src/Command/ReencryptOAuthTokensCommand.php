<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AuthIdentity;
use App\Security\Crypto\SecretBox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Step 2 of an OAUTH_TOKEN_ENCRYPTION_KEY rotation (docs/SECURITY.md): re-encrypts every stored
 * provider token still under the previous key, after which the previous key can be dropped.
 */
#[AsCommand(name: 'app:oauth-tokens:reencrypt', description: 'Re-encrypts stored OAuth tokens with the current key')]
final class ReencryptOAuthTokensCommand extends Command
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SecretBox $secretBox,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $query = $this->entityManager->createQuery(sprintf('SELECT i FROM %s i WHERE i.accessTokenEncrypted IS NOT NULL', AuthIdentity::class));
        $reencrypted = 0;
        $unreadable = 0;
        $seen = 0;

        /** @var AuthIdentity $identity */
        foreach ($query->toIterable() as $identity) {
            $encrypted = (string) $identity->getAccessTokenEncrypted();

            try {
                if ($this->secretBox->needsReencryption($encrypted)) {
                    $identity->setAccessTokenEncrypted($this->secretBox->encrypt($this->secretBox->decrypt($encrypted)));
                    ++$reencrypted;
                }
            } catch (\UnexpectedValueException) {
                // Neither key opens it: leave it (it's useless but harmless); the next provider
                // login replaces it.
                ++$unreadable;
            }

            if (0 === ++$seen % self::BATCH_SIZE) {
                $this->entityManager->flush();
                $this->entityManager->clear();
            }
        }

        $this->entityManager->flush();

        $io->success(sprintf('%d token(s) re-encrypted with the current key.', $reencrypted));

        if ($unreadable > 0) {
            $io->warning(sprintf('%d token(s) could not be decrypted with either key.', $unreadable));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
