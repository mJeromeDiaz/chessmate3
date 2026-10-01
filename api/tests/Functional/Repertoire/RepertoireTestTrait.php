<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repertoire;

use App\Chess\Pgn\Node;
use App\Chess\Pgn\Parser;
use App\Chess\Position\PositionKey;
use App\Chess\Rules;
use App\Entity\Repertoire\Move;
use App\Entity\Repertoire\Position;
use App\Entity\Repertoire\Repertoire;
use App\Entity\User;
use App\Enum\Repertoire\Color;
use App\Repertoire\Graph\Change;
use App\Repertoire\Graph\GraphEditor;
use App\Repertoire\Graph\GraphIndexer;
use App\Repertoire\Graph\GraphLoader;
use App\Repertoire\Graph\IndexWriter;
use App\Repertoire\Graph\RestorePlanner;
use App\Repertoire\Graph\RowStore;
use App\Repertoire\Graph\SegmentReconciler;
use App\Repertoire\Graph\TrashBin;
use App\Repertoire\Limits;
use App\Repertoire\RepertoireManager;
use App\Repertoire\Transaction;
use App\Repository\Repertoire\MoveRepository;
use App\Repository\Repertoire\PositionRepository;
use App\Repository\Repertoire\RepertoireRepository;
use App\Repository\Repertoire\RevisionRepository;
use Psr\Clock\ClockInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Builds repertoires through the real services, and reads them back in plain SQL.
 */
trait RepertoireTestTrait
{
    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function editor(): GraphEditor
    {
        return self::getContainer()->get(GraphEditor::class);
    }

    private function createUser(string $email = 'alice@example.com'): User
    {
        $user = new User();
        $user->setEmail($email);
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function createRepertoire(User $user, Color $color = Color::White, string $name = 'Test'): Repertoire
    {
        return self::getContainer()->get(RepertoireManager::class)->create($user, $name, $color);
    }

    private function root(Repertoire $repertoire): Position
    {
        $hash = PositionKey::of(Rules::initial()->normalizedFen())->hash;

        return $this->em()->getRepository(Position::class)->findOneBy(['repertoire' => $repertoire, 'fenHash' => $hash]) ?? throw new \LogicException('No root.');
    }

    /**
     * Plays SAN moves from the initial position, adding the missing ones.
     *
     * @return list<Change>
     */
    private function line(Repertoire $repertoire, string $sans): array
    {
        $position = $this->root($repertoire);
        $changes = [];
        foreach (explode(' ', $sans) as $san) {
            [$change, $position] = $this->play($repertoire, $position, $san);
            $changes[] = $change;
        }

        return $changes;
    }

    /**
     * @return array{Change, Position}
     */
    private function play(Repertoire $repertoire, Position $from, string $san): array
    {
        $move = Rules::fromFen($from->getFen())->playSan($san) ?? throw new \LogicException('Illegal '.$san);
        $change = $this->editor()->addMove($repertoire->getUser(), $repertoire->getId(), $from->getId(), Rules::uci($move));

        return [$change, $this->move((string) $change->moveId)->getTo()];
    }

    private function pgn(Repertoire $repertoire, string $pgn): void
    {
        foreach ((new Parser())->parse($pgn) as $game) {
            $this->tree($repertoire, $game->root, $this->root($repertoire));
        }
    }

    private function tree(Repertoire $repertoire, Node $node, Position $position): void
    {
        foreach ($node->children as $child) {
            $this->tree($repertoire, $child, $this->play($repertoire, $position, $child->san)[1]);
        }
    }

    private function move(string $id): Move
    {
        return $this->em()->find(Move::class, Uuid::fromString($id)) ?? throw new \LogicException('No move '.$id);
    }

    /** The id of the move $san played after the SAN moves $path from the initial position. */
    private function moveId(Repertoire $repertoire, string $path, string $san): string
    {
        $rules = Rules::initial();
        foreach (array_filter(explode(' ', $path)) as $step) {
            $rules->playSan($step) ?? throw new \LogicException($step);
        }
        $from = $rules->normalizedFen();
        $uci = Rules::uci($rules->playSan($san) ?? throw new \LogicException($san));
        $id = $this->connection()->fetchOne(
            'SELECT m.id FROM repertoire_move m JOIN repertoire_position p ON p.id = m.from_position_id WHERE m.repertoire_id = ? AND p.fen = ? AND m.uci = ?',
            [$repertoire->getId()->toBinary(), $from, $uci],
            [ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING],
        );

        return \is_string($id) ? Uuid::fromBinary($id)->toRfc4122() : throw new \LogicException('No move '.$san);
    }

    /**
     * The whole stored state of a repertoire (graph, derived data, active segments), for equality.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Repertoire $repertoire): array
    {
        $id = [$repertoire->getId()->toBinary()];
        $types = [ParameterType::BINARY];

        return [
            'positions' => $this->connection()->fetchAllAssociative('SELECT HEX(id) id, fen, depth FROM repertoire_position WHERE repertoire_id = ? ORDER BY id', $id, $types),
            'moves' => $this->connection()->fetchAllAssociative('SELECT HEX(id) id, HEX(from_position_id) f, HEX(to_position_id) t, uci, san, role, sort_order, comment, nags, canonical, HEX(segment_id) segment FROM repertoire_move WHERE repertoire_id = ? ORDER BY id', $id, $types),
            'segments' => $this->connection()->fetchAllAssociative('SELECT HEX(id) id, HEX(start_move_id) start, HEX(derived_from_segment_id) derived, move_count, user_move_count FROM repertoire_segment WHERE repertoire_id = ? AND archived_at IS NULL ORDER BY id', $id, $types),
            'positionCount' => $this->connection()->fetchOne('SELECT position_count FROM repertoire WHERE id = ?', $id, $types),
        ];
    }

    /**
     * Active segments as "SAN SAN... (n user moves)", the trunk first, then by first move.
     *
     * @return array<string, string> segment id => description
     */
    private function segments(Repertoire $repertoire): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT s.id, s.start_move_id, s.user_move_count, m.san FROM repertoire_segment s
             JOIN repertoire_move m ON m.segment_id = s.id
             JOIN repertoire_position p ON p.id = m.from_position_id
             WHERE s.repertoire_id = ? AND s.archived_at IS NULL
             ORDER BY s.start_move_id IS NOT NULL, s.start_move_id, p.depth',
            [$repertoire->getId()->toBinary()],
            [ParameterType::BINARY],
        );
        $sans = $users = $trunk = [];
        foreach ($rows as $row) {
            self::assertIsString($row['id']);
            self::assertIsString($row['san']);
            self::assertIsNumeric($row['user_move_count']);
            $id = Uuid::fromBinary($row['id'])->toRfc4122();
            $sans[$id][] = $row['san'];
            $users[$id] = (int) $row['user_move_count'];
            $trunk[$id] = null === $row['start_move_id'];
        }
        $out = [];
        foreach ($sans as $id => $list) {
            $out[$id] = ($trunk[$id] ? 'trunk: ' : '').implode(' ', $list).sprintf(' (%d user moves)', $users[$id]);
        }

        return $out;
    }

    /** An editor with other limits than the application's. */
    private function editorWith(Limits $limits): GraphEditor
    {
        $container = self::getContainer();

        return new GraphEditor(
            $this->em(),
            $this->connection(),
            $container->get(RepertoireRepository::class),
            $container->get(PositionRepository::class),
            $container->get(MoveRepository::class),
            $container->get(RevisionRepository::class),
            $container->get(GraphLoader::class),
            $container->get(GraphIndexer::class),
            $container->get(IndexWriter::class),
            $container->get(SegmentReconciler::class),
            $container->get(RowStore::class),
            $container->get(TrashBin::class),
            $container->get(RestorePlanner::class),
            $limits,
            $container->get(ClockInterface::class),
            $container->get(Transaction::class),
        );
    }
}
