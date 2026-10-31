<?php

declare(strict_types=1);

namespace App\Tests\Functional\Guest;

use App\Entity\User;
use App\Enum\AuthProvider;
use App\Tests\Double\FakeLichessProvider;
use App\Tests\Functional\Auth\OAuthWebTestCase;
use Doctrine\DBAL\Connection;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A guest becomes an account (docs/GUEST.md): its browser (the refresh cookie) signs up with
 * Lichess and an invitation key, or with its email once verified; the account is that guest,
 * everything it played kept. Without a key it stays a guest; a Lichess account already here signs
 * in and leaves the guest behind.
 */
final class GuestPromotionTest extends OAuthWebTestCase
{
    private const PASSWORD = 'CorrectHorseBatteryStaple9!';
    private const ACCOUNT = [
        'id' => 'magnus',
        'username' => 'Magnus',
        'perfs' => ['blitz' => ['games' => 1200, 'rating' => 2250, 'rd' => 45, 'prog' => 3]],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        FakeLichessProvider::reset();
    }

    public function testAGuestBecomesItsLichessAccountWithWhatItPlayed(): void
    {
        $guest = $this->openGuest();
        $this->played($guest);

        [$state, $challenge] = $this->startOAuthFlow(AuthProvider::Lichess);
        $response = $this->oauthCallback(AuthProvider::Lichess, ['state' => $state, 'code' => FakeLichessProvider::consent($challenge, self::ACCOUNT)]);

        self::assertSame(['status' => 'success', 'mode' => 'login', 'provider' => 'lichess'], $this->spaOutcome($response));
        $user = $this->findIdentity(AuthProvider::Lichess, 'magnus')->getUser();
        self::assertTrue($user->getId()->equals($guest->getId()), 'The account is the guest.');
        self::assertFalse($user->isGuest());
        self::assertContains('ROLE_USER', $user->getRoles());
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM mind_map_progress WHERE user_id = ?', $guest), 'What it played is kept.');
        self::assertSame(1, $this->userRepository->count([]));
    }

    public function testWithoutAKeyTheGuestStaysAGuest(): void
    {
        $guest = $this->openGuest();

        [$state, $challenge] = $this->startOAuthFlowWith(AuthProvider::Lichess, null);
        $response = $this->oauthCallback(AuthProvider::Lichess, ['state' => $state, 'code' => FakeLichessProvider::consent($challenge, self::ACCOUNT)]);

        self::assertSame('invitation_required', $this->spaOutcome($response)['reason'] ?? null);
        self::assertTrue($this->reloadUser($guest)->isGuest());
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM auth_identity WHERE user_id = ?', $guest));
    }

    public function testALichessAccountAlreadyHereSignsInAndLeavesTheGuest(): void
    {
        [$state, $challenge] = $this->startOAuthFlow(AuthProvider::Lichess);
        $this->oauthCallback(AuthProvider::Lichess, ['state' => $state, 'code' => FakeLichessProvider::consent($challenge, self::ACCOUNT)]);
        $account = $this->findIdentity(AuthProvider::Lichess, 'magnus')->getUser();
        $this->client->getCookieJar()->clear();
        $guest = $this->openGuest();

        [$state, $challenge] = $this->startOAuthFlowWith(AuthProvider::Lichess, null);
        $response = $this->oauthCallback(AuthProvider::Lichess, ['state' => $state, 'code' => FakeLichessProvider::consent($challenge, self::ACCOUNT)]);

        self::assertSame('success', $this->spaOutcome($response)['status'] ?? null);
        self::assertTrue($this->findIdentity(AuthProvider::Lichess, 'magnus')->getUser()->getId()->equals($account->getId()));
        self::assertTrue($this->reloadUser($guest)->isGuest(), 'Left behind, purged once unreachable.');
    }

    public function testAGuestRegistersWithItsEmailAndBecomesTheAccountOnceVerified(): void
    {
        $guest = $this->openGuest();
        $this->played($guest);

        $this->client->request('POST', '/api/auth/register', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => 'alice@example.com',
            'password' => self::PASSWORD,
            'invitationKey' => $this->createInvitationKey(),
        ], \JSON_THROW_ON_ERROR));
        self::assertSame(202, $this->client->getResponse()->getStatusCode());

        $pending = $this->reloadUser($guest);
        self::assertSame(['alice@example.com', true, false], [$pending->getEmail(), $pending->isGuest(), $pending->isEmailVerified()], 'A guest until its email is proven.');
        self::assertSame(1, $this->userRepository->count([]));

        /** @var VerifyEmailHelperInterface $helper */
        $helper = self::getContainer()->get(VerifyEmailHelperInterface::class);
        $url = $helper->generateSignature('app_verify_email', $guest->getId()->toRfc4122(), 'alice@example.com', ['id' => $guest->getId()->toRfc4122()])->getSignedUrl();
        $this->client->request('GET', parse_url($url, \PHP_URL_PATH).'?'.parse_url($url, \PHP_URL_QUERY));
        self::assertSame(302, $this->client->getResponse()->getStatusCode());

        $account = $this->reloadUser($guest);
        self::assertSame([false, true], [$account->isGuest(), $account->isEmailVerified()]);
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM mind_map_progress WHERE user_id = ?', $guest));
    }

    public function testAnEmailAlreadyTakenLeavesTheGuestUntouched(): void
    {
        $this->createVerifiedUser('alice@example.com');
        $guest = $this->openGuest();

        $this->client->request('POST', '/api/auth/register', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => 'alice@example.com',
            'password' => self::PASSWORD,
            'invitationKey' => $this->createInvitationKey(),
        ], \JSON_THROW_ON_ERROR));

        self::assertSame(202, $this->client->getResponse()->getStatusCode(), 'The same answer as ever.');
        $left = $this->reloadUser($guest);
        self::assertSame([null, true], [$left->getEmail(), $left->isGuest()]);
    }

    /** « Essayer sans compte » in this browser: the client keeps its refresh cookie. */
    private function openGuest(): User
    {
        $this->client->request('POST', '/api/auth/guest', server: ['HTTP_X-Guest-Request' => '1']);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $guest = $this->userRepository->findOneBy(['guest' => true]);
        self::assertInstanceOf(User::class, $guest);
        self::assertTrue($guest->isGuest());

        return $guest;
    }

    /** A step of a path, as if played. */
    private function played(User $user): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO mind_map_progress (id, user_id, capacity, step, passes, completed_at, updated_at) VALUES (?, ?, 'visualisation', '1-1', 2, NOW(), NOW())",
            [Uuid::v7()->toBinary(), $user->getId()->toBinary()],
        );
    }

    private function rows(string $sql, User $user): int
    {
        $count = $this->connection()->fetchOne($sql, [$user->getId()->toBinary()]);

        return is_numeric($count) ? (int) $count : -1;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
