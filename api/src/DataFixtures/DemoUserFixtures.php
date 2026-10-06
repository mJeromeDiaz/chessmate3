<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use App\Enum\Avatar;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A demo account for the dev database, owner of the demo training data. Its credentials are public
 * (docs/WOODPECKER.md): fixtures are never loaded outside dev and e2e (docs/SECURITY.md, deployment
 * checklist). The login still asks for the email code: in dev, read it in the profiler's mailer panel.
 */
final class DemoUserFixtures extends Fixture
{
    public const EMAIL = 'demo@dontstayrooky.test';
    public const PASSWORD = 'dontstayrooky-demo';
    public const REFERENCE = 'demo-user';

    public function __construct(private readonly UserPasswordHasherInterface $hasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $user = new User();
        $user->setEmail(self::EMAIL);
        $user->markEmailVerified();
        $user->setPassword($this->hasher->hashPassword($user, self::PASSWORD));
        $user->setTimezone('Europe/Paris');
        $user->setDisplayName('Joueur démo')->setHandle('demo')->setAvatar(Avatar::Knight);
        $manager->persist($user);
        $manager->flush();

        $this->addReference(self::REFERENCE, $user);
    }
}
