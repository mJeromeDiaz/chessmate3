<?php

declare(strict_types=1);

namespace App\Tests\Functional\Entity;

use App\Entity\AuthIdentity;
use App\Entity\Catalog\Puzzle;
use App\Entity\User;
use App\Enum\AuthProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * MySQL's default collation (utf8mb4_0900_ai_ci) ignores case and accents: every column holding a
 * case-sensitive identifier, a FEN, moves or a digest looked up by an index must be binary.
 */
final class CaseSensitiveColumnsTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    /** The puzzle catalogue's entity manager: its own database (docs/DEPLOY_OVH.md, § 3). */
    private EntityManagerInterface $catalog;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->catalog = self::getContainer()->get('doctrine.orm.catalog_entity_manager');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function binaryColumns(): iterable
    {
        foreach ([
            ['puzzle', 'lichess_id'],
            ['puzzle', 'fen'],
            ['puzzle', 'moves'],
            ['puzzle_theme', 'theme_key'],
            ['activity_log_entry', 'source_type'],
            ['activity_log_entry', 'source_id'],
            ['auth_identity', 'provider_user_id'],
            ['reset_password_request', 'selector'],
            ['oauth_flow', 'binding_hash'],
            ['oauth_flow', 'state_hash'],
            ['oauth_flow', 'code_verifier'],
            ['oauth_flow', 'registration_ticket'],
            ['mfa_challenge', 'pending_token_hash'],
            ['mfa_challenge', 'code_hash'],
            ['account_deletion_code', 'code_hash'],
            ['gamification_xp_entry', 'source_type'],
            ['gamification_xp_entry', 'source_id'],
            ['gamification_quest', 'theme'],
            ['trusted_device', 'token_hash'],
            ['refresh_token', 'refresh_token'],
            ['app_user', 'handle'],
            ['repertoire_position', 'fen'],
            ['repertoire_move', 'uci'],
            ['repertoire_move', 'san'],
            ['repertoire_opening', 'epd'],
            ['repertoire_opening', 'uci'],
            ['repertoire_trash', 'from_fen'],
            ['repertoire_trash', 'uci'],
            ['repertoire_trash', 'san'],
            ['repertoire_card', 'fen'],
            ['repertoire_card', 'uci'],
            ['repertoire_review', 'played_uci'],
            ['repertoire_presentation', 'start_fen'],
            ['training_calendar_feed', 'token_hash'],
            ['training_calendar_feed', 'encrypted_token'],
            ['early_access_invitation_key', 'key_hash'],
            ['early_access_invitation_key', 'key_hint'],
        ] as [$table, $column]) {
            yield "$table.$column" => [$table, $column];
        }
    }

    #[DataProvider('binaryColumns')]
    public function testColumnUsesABinaryCollation(string $table, string $column): void
    {
        $manager = \in_array($table, ['puzzle', 'puzzle_theme'], true) ? $this->catalog : $this->entityManager;
        $collation = $manager->getConnection()->fetchOne(
            'SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column],
        );

        self::assertIsString($collation, "$table.$column not found");
        self::assertStringEndsWith('_bin', $collation);
    }

    public function testPuzzleIdsDifferingOnlyByCaseAreDistinct(): void
    {
        $this->catalog->persist($this->puzzle('0009B'));
        $this->catalog->persist($this->puzzle('0009b'));
        // The unique index on lichess_id would reject the second row under a case-insensitive collation.
        $this->catalog->flush();
        $this->catalog->clear();

        $repository = $this->catalog->getRepository(Puzzle::class);
        self::assertSame('0009B', $repository->findOneBy(['lichessId' => '0009B'])?->getLichessId());
        self::assertSame('0009b', $repository->findOneBy(['lichessId' => '0009b'])?->getLichessId());
        self::assertNull($repository->findOneBy(['lichessId' => '0009C']));
    }

    public function testFensDifferingOnlyByCaseAreNotEqual(): void
    {
        // Same squares, colours swapped: only the case of the piece letters differs.
        $this->catalog->persist($this->puzzle('zz001', '4k3/8/8/8/8/8/8/4K2R w - - 0 1'));
        $this->catalog->flush();

        $connection = $this->catalog->getConnection();

        self::assertSame([], $connection->fetchFirstColumn('SELECT id FROM puzzle WHERE fen = ?', ['4K3/8/8/8/8/8/8/4k2r w - - 0 1']));
        self::assertCount(1, $connection->fetchFirstColumn('SELECT id FROM puzzle WHERE fen = ?', ['4k3/8/8/8/8/8/8/4K2R w - - 0 1']));
    }

    public function testOAuthSubjectsDifferingOnlyByCaseAreDistinct(): void
    {
        $first = new User();
        $first->setEmail('case-first@example.com');
        new AuthIdentity($first, AuthProvider::Lichess, 'Magnus');
        $second = new User();
        $second->setEmail('case-second@example.com');
        new AuthIdentity($second, AuthProvider::Lichess, 'magnus');

        $this->entityManager->persist($first);
        $this->entityManager->persist($second);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $repository = $this->entityManager->getRepository(AuthIdentity::class);
        $found = $repository->findOneBy(['provider' => AuthProvider::Lichess, 'providerUserId' => 'magnus']);
        self::assertSame('case-second@example.com', $found?->getUser()->getEmail());
    }

    private function puzzle(string $lichessId, string $fen = 'r1bqkbnr/pppp1ppp/2n5/4p3/4P3/5N2/PPPP1PPP/RNBQKB1R w KQkq - 2 3'): Puzzle
    {
        return new Puzzle($lichessId, $fen, 'f1c4 g8f6', 1500, 80, 90, 1000, ['short'], 'https://lichess.org/abcdefgh');
    }
}
