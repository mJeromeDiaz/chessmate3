<?php

declare(strict_types=1);

namespace App\Tests\Functional\Woodpecker;

use App\Entity\User;
use App\Entity\Woodpecker\Set;
use App\Enum\Repertoire\Color;
use App\Enum\Woodpecker\SetStatus;
use App\Woodpecker\Set\OpeningSets;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The opening sets of the tailored session (docs/COACH.md): light sets drawn from an opening
 * family, one ongoing per colour besides the usual light set, the player's side first, both sides
 * when too few, none under the minimum; played in a session step through its setId.
 */
final class OpeningSetTest extends WoodpeckerWebTestCase
{
    use OpeningPuzzlesTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->addPuzzles('a', 'Scotch_Game', self::WHITE_SOLVES, 110);
        $this->addPuzzles('b', 'Scotch_Game', self::BLACK_SOLVES, 5);
        $this->addPuzzles('c', 'Philidor_Defense', self::WHITE_SOLVES, 20);
        $this->addPuzzles('d', 'Philidor_Defense', self::BLACK_SOLVES, 20);
        $this->addPuzzles('e', 'Center_Game', self::BLACK_SOLVES, 10);
        // Out of range: never drawn.
        $this->addPuzzles('f', 'Philidor_Defense', self::BLACK_SOLVES, 80, 2500);
        $this->catalog->flush();
        $this->catalog->clear();
    }

    public function testTheUsersSideFirstThenBothSidesThenNone(): void
    {
        $alice = $this->createUserIn('alice@example.com');

        $white = $this->sets()->ensure($alice, 'Scotch Game', Color::White);
        self::assertInstanceOf(Set::class, $white);
        self::assertSame(['Scotch Game · Blancs', 100, 1050, 1350], [$white->getName(), $white->getPuzzleCount(), $white->getRatingMin(), $white->getRatingMax()]);
        self::assertEquals(['Scotch_Game', Color::White, false], [$white->getOpening()?->family, $white->getOpening()?->color, $white->getOpening()?->bothColors]);
        self::assertSame([['Scotch_Game', 'w']], $this->drawnFrom($white));
        self::assertSame($white->getId()->toRfc4122(), $this->sets()->ensure($alice, 'Scotch Game', Color::White)?->getId()->toRfc4122(), 'kept: same family');

        // 20 with Black to play: both sides, all 40 of them.
        $black = $this->sets()->ensure($alice, 'Philidor Defense', Color::Black);
        self::assertInstanceOf(Set::class, $black);
        self::assertSame([40, true], [$black->getPuzzleCount(), $black->getOpening()?->bothColors]);
        self::assertSame([['Philidor_Defense', 'b'], ['Philidor_Defense', 'w']], $this->drawnFrom($black));

        // 10 puzzles: no set, the Philidor one stays.
        self::assertNull($this->sets()->ensure($alice, 'Center Game', Color::Black));
        self::assertNull($this->sets()->ensure($alice, 'Unknown Opening', Color::Black));
        $this->entityManager->clear();
        self::assertSame(SetStatus::Active, $this->entityManager->find(Set::class, $black->getId())?->getStatus());
    }

    public function testAnotherFamilyReplacesTheSetOfThatColourAndTheUsualLightSetStays(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $light = $this->api('POST', '/api/woodpecker/sets', $alice, ['name' => 'Light', 'mode' => 'light', 'ratingMin' => 400, 'ratingMax' => 3200]);
        self::assertSame(201, $light->getStatusCode(), (string) $light->getContent());

        $philidor = $this->sets()->ensure($alice, 'Philidor Defense', Color::White);
        self::assertInstanceOf(Set::class, $philidor);
        $scotch = $this->sets()->ensure($alice, 'Scotch Game', Color::White);
        self::assertInstanceOf(Set::class, $scotch);
        self::assertNotNull($this->sets()->ensure($alice, 'Philidor Defense', Color::Black));

        $this->entityManager->clear();
        self::assertSame(SetStatus::Abandoned, $this->entityManager->find(Set::class, $philidor->getId())?->getStatus(), 'replaced, history kept');
        $listed = $this->json($this->api('GET', '/api/woodpecker/sets', $alice))['member'] ?? null;
        self::assertIsArray($listed);
        $ongoing = array_filter($listed, static fn (mixed $set): bool => \is_array($set) && \in_array($set['status'] ?? null, ['active', 'paused'], true));
        self::assertEqualsCanonicalizing(
            [null, ['family' => 'Scotch_Game', 'color' => 'white', 'bothColors' => false], ['family' => 'Philidor_Defense', 'color' => 'black', 'bothColors' => true]],
            array_column($ongoing, 'opening'),
        );
        // Still one usual light set only.
        self::assertSame(409, $this->api('POST', '/api/woodpecker/sets', $alice, ['name' => 'Again', 'mode' => 'light'])->getStatusCode());
    }

    public function testASessionStepPlaysTheSetItNames(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $bob = $this->createUserIn('bob@example.com');
        $set = $this->sets()->ensure($alice, 'Scotch Game', Color::White);
        self::assertInstanceOf(Set::class, $set);
        $step = ['module' => 'woodpecker', 'minutes' => 10, 'settings' => ['setId' => $set->getId()->toRfc4122()]];

        foreach ([['setId' => 'nope'], ['setId' => $set->getId()->toRfc4122(), 'size' => 3]] as $settings) {
            self::assertSame(422, $this->api('POST', '/api/training/sessions', $alice, ['steps' => [['settings' => $settings] + $step]])->getStatusCode());
        }
        self::assertSame(422, $this->api('POST', '/api/training/sessions', $bob, ['steps' => [$step]])->getStatusCode(), 'not his set');

        $session = $this->api('POST', '/api/training/sessions', $alice, ['steps' => [$step]]);
        self::assertSame(201, $session->getStatusCode(), (string) $session->getContent());
        $sessionId = $this->json($session)['id'] ?? null;
        self::assertIsString($sessionId);
        $next = $this->json($this->api('POST', '/api/training/sessions/'.$sessionId.'/next', $alice));
        self::assertIsArray($next['run'] ?? null);
        self::assertSame(['woodpecker', $set->getId()->toRfc4122()], [$next['run']['module'] ?? null, $next['run']['subjectId'] ?? null]);
        $runId = $next['run']['id'] ?? null;
        self::assertIsString($runId);
        $item = $this->runNext($alice, $runId);
        self::assertSame('white', $item['item']['data']['puzzle']['playerColor'] ?? null);
    }

    /**
     * The (family, solver side) pairs of a set's puzzles, read in the catalogue apart.
     *
     * @return list<array{string, string}>
     */
    private function drawnFrom(Set $set): array
    {
        $ids = $this->entityManager->getConnection()->fetchFirstColumn('SELECT puzzle_id FROM woodpecker_set_puzzle WHERE set_id = ?', [$set->getId()->toBinary()]);
        /** @var Connection $catalog */
        $catalog = self::getContainer()->get('doctrine.dbal.catalog_connection');
        $rows = $catalog->fetchAllNumeric('SELECT DISTINCT opening_family, solver_color FROM puzzle WHERE id IN (?) ORDER BY opening_family, solver_color', [$ids], [ArrayParameterType::INTEGER]);

        /** @var list<array{string, string}> */
        return $rows;
    }

    private function sets(): OpeningSets
    {
        return self::getContainer()->get(OpeningSets::class);
    }
}
