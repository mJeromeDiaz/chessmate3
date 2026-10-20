<?php

declare(strict_types=1);

namespace App\Notation\Drawing;

use App\Chess\InvalidPositionException;
use App\Chess\Rules;
use App\Entity\Repertoire\Repertoire;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Repertoire\TestUnit;
use App\Notation\NotationRules;
use App\Repertoire\Training\UnitCatalog;
use App\Repository\Notation\AttemptRepository;
use App\Repository\Repertoire\RepertoireRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Random\Randomizer;
use Symfony\Component\Uid\Uuid;

/**
 * Draws a line of the player's repertoires to complete in notation (docs/NOTATION.md, § 3,
 * « line »): a repertoire with at least LINE_MIN_SEGMENTS segments (the one chosen, or any of
 * them), a line of its canonical tree (docs/REPERTOIRE.md, § 15) of LINE_MIN_PLIES half-moves at
 * least, played up to its LINE_WRITE_PLIES last ones, to write. Never a line already served in the
 * run while another one is left.
 */
final readonly class LinePicker
{
    private Randomizer $randomizer;

    public function __construct(
        private RepertoireRepository $repertoires,
        private UnitCatalog $catalog,
        private AttemptRepository $attempts,
        private Connection $connection,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer();
    }

    /**
     * The repertoires of the player fit for the exercise.
     *
     * @return list<array{repertoire: Repertoire, segments: int, eligible: bool}>
     */
    public function standings(User $user): array
    {
        return array_map(function (Repertoire $repertoire): array {
            $segments = \count($this->catalog->builder($repertoire)->presentable());

            return ['repertoire' => $repertoire, 'segments' => $segments, 'eligible' => $segments >= NotationRules::LINE_MIN_SEGMENTS];
        }, $this->repertoires->findByUser($user));
    }

    /**
     * @param Uuid|null $repertoireId the repertoire chosen, null: any fit one
     *
     * @return Exercise|null null when no repertoire fits, or none of its lines is long enough
     */
    public function pick(Run $run, ?Uuid $repertoireId): ?Exercise
    {
        $user = $run->getUser();
        $chosen = null === $repertoireId ? null : $this->repertoires->findOwned($repertoireId, $user);
        $candidates = null === $repertoireId ? $this->repertoires->findByUser($user) : (null === $chosen ? [] : [$chosen]);
        $served = $this->attempts->lineKeysOfRun($run);
        $fallback = null;
        foreach ($this->randomizer->shuffleArray($candidates) as $repertoire) {
            $builder = $this->catalog->builder($repertoire);
            if (\count($builder->presentable()) < NotationRules::LINE_MIN_SEGMENTS) {
                continue;
            }
            foreach ($this->randomizer->shuffleArray($builder->units(TestUnit::Line)) as $plan) {
                $exercise = $this->exercise(
                    $plan->key,
                    [...$plan->contextMoveIds, ...$plan->moveIds()],
                    $builder->label($plan->key)['opening']['name'] ?? null,
                    $repertoire->getColor()->value,
                );
                if (null === $exercise) {
                    continue;
                }
                if (!\in_array($plan->key, $served, true)) {
                    return $exercise;
                }
                $fallback ??= $exercise;
            }
        }

        return $fallback;
    }

    /**
     * A line as an item: played up to its last half-moves; null when it is too short or does not
     * replay.
     *
     * @param list<string> $moveIds from the initial position, in order
     */
    private function exercise(string $key, array $moveIds, ?string $label, string $orientation): ?Exercise
    {
        if (\count($moveIds) < NotationRules::LINE_MIN_PLIES) {
            return null;
        }
        $ucis = $this->ucis($moveIds);
        if (\count($ucis) !== \count($moveIds)) {
            return null;
        }
        $split = \count($ucis) - min(NotationRules::LINE_WRITE_PLIES, \count($ucis) - 2);
        try {
            $rules = Rules::initial();
            foreach (\array_slice($ucis, 0, $split) as $uci) {
                if (null === $rules->playUci($uci)) {
                    return null;
                }
            }
            $fen = $rules->fen();
            foreach (\array_slice($ucis, $split) as $uci) {
                if (null === $rules->playUci($uci)) {
                    return null;
                }
            }
        } catch (InvalidPositionException) {
            return null;
        }

        return new Exercise(null, $fen, \array_slice($ucis, $split), \array_slice($ucis, 0, $split), $key, $label, $orientation);
    }

    /**
     * The UCI of repertoire moves, in the order of their ids (a missing one left out).
     *
     * @param list<string> $moveIds RFC 4122
     *
     * @return list<string>
     */
    private function ucis(array $moveIds): array
    {
        $byId = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT id, uci FROM repertoire_move WHERE id IN (?)',
            [array_map(static fn (string $id): string => Uuid::fromString($id)->toBinary(), $moveIds)],
            [ArrayParameterType::BINARY],
        ) as $row) {
            if (\is_string($row['id']) && \is_string($row['uci'])) {
                $byId[Uuid::fromBinary($row['id'])->toRfc4122()] = $row['uci'];
            }
        }

        return array_values(array_filter(array_map(static fn (string $id): ?string => $byId[$id] ?? null, $moveIds)));
    }
}
