<?php

declare(strict_types=1);

namespace App\Repertoire\Auto;

use App\Entity\Repertoire\AutoBuild;
use App\Entity\User;
use App\Enum\AuthProvider;
use App\Enum\Repertoire\AutoBuildStatus;
use App\Repertoire\Exception\LimitReachedException;
use App\Repertoire\Lichess\CloudEvalClient;
use App\Repertoire\Lichess\LichessUnavailableException;
use App\Repertoire\Lichess\TokenResolver;
use App\Repertoire\Limits;
use App\Repository\Repertoire\AutoBuildRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The life of a repertoire built from the user's Lichess games (docs/REPERTOIRE.md, "Repertoire
 * from my games"): created with its settings, filled a batch of games per request (the SPA asks
 * until it is ready: real progress, no worker, fit for the shared host), read as a synthesis, then
 * shaped by the user (openings kept, moves chosen, lines removed: {@see Selection}), its line ends
 * evaluated by the Lichess cloud a few per request, and applied: one repertoire per opening.
 *
 * @phpstan-type FeedItem array{id: string, color: string, result: int, opponent: string|null, rating: int|null, family: string, perf: string, endgame: string|null}
 */
final readonly class AutoBuildService
{
    /** Games a build may read (the choices offered). */
    public const GAME_COUNTS = [50, 100, 200, 500, 1000, 2000];
    /** Lichess speeds offered. */
    public const PERFS = ['bullet', 'blitz', 'rapid', 'classical'];
    /** Lichess sends games the repertoire cannot use (variants, aborted...): reading stops after this many times the games wanted. */
    private const SCAN_FACTOR = 4;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private AutoBuildRepository $builds,
        private GamesClient $games,
        private TokenResolver $tokens,
        private TreeBuilder $treeBuilder,
        private CloudEvalClient $cloudEval,
        private RepertoireWriter $writer,
        private Limits $limits,
        private ClockInterface $clock,
    ) {
    }

    /**
     * A new build, replacing the user's previous one.
     *
     * @param list<string> $perfs
     *
     * @throws AutoBuildException
     */
    public function create(User $user, int $maxGames, array $perfs): AutoBuild
    {
        $perfs = array_values(array_unique($perfs));
        if (!\in_array($maxGames, self::GAME_COUNTS, true) || $maxGames > $this->limits->autoMaxGames || [] === $perfs || [] !== array_diff($perfs, self::PERFS)) {
            throw new AutoBuildException(AutoBuildException::INVALID_SETTINGS);
        }
        $username = self::lichessId($user) ?? throw new AutoBuildException(AutoBuildException::LICHESS_NOT_LINKED);
        $now = $this->clock->now();
        $this->builds->purge($user, $now);
        $build = new AutoBuild($user, $username, $maxGames, $perfs, $now, $now->modify(\sprintf('+%d hours', $this->limits->autoTtlHours)));
        $this->entityManager->persist($build);
        $this->entityManager->flush();

        return $build;
    }

    public function get(User $user, Uuid $id): ?AutoBuild
    {
        return $this->builds->findOwned($id, $user, $this->clock->now());
    }

    /**
     * Reads the next batch of games, unless every game is in already.
     *
     * @return list<FeedItem> the games kept from this batch, newest first
     *
     * @throws LichessUnavailableException
     */
    public function fetch(AutoBuild $build): array
    {
        if (AutoBuildStatus::Fetching !== $build->getStatus()) {
            return [];
        }
        $wanted = min($this->limits->autoBatchGames, $build->getMaxGames() - $build->getGameCount());
        $raw = $this->games->batch($build->getUsername(), $this->tokens->userToken($build->getUser()), $wanted, $build->getUntilMs(), $build->getPerfs());
        if (null === $raw) {
            $build->ready('lichess_user_not_found');
            $this->entityManager->flush();

            return [];
        }

        $games = GameRecord::listFromJson($build->getGames());
        $feed = [];
        $oldest = null;
        foreach ($raw as $game) {
            $createdAt = $game['createdAt'] ?? null;
            if (\is_int($createdAt)) {
                $oldest = null === $oldest ? $createdAt : min($oldest, $createdAt);
            }
            $record = GameReader::read($game, $build->getUsername(), $this->limits->autoMaxPlies);
            if (null === $record || \count($games) >= $build->getMaxGames()) {
                continue;
            }
            $games[] = $record;
            $feed[] = ['id' => $record->id, 'color' => $record->color->value, 'result' => $record->result, 'opponent' => $record->opponent, 'rating' => $record->opponentRating, 'family' => $record->family, 'perf' => $record->perf, 'endgame' => $record->endgameType?->value];
        }
        $build->batchRead(GameRecord::listToJson($games), \count($games), \count($raw), null === $oldest ? $build->getUntilMs() : $oldest - 1);
        $exhausted = \count($raw) < $wanted || null === $oldest;
        if ($exhausted || \count($games) >= $build->getMaxGames() || $build->getScanned() >= self::SCAN_FACTOR * $build->getMaxGames()) {
            $build->ready();
        }
        $this->entityManager->flush();

        return $feed;
    }

    /**
     * Keeps the user's selection (it replaces the previous one).
     *
     * @throws AutoBuildException not_ready, already_applied, invalid_selection
     */
    public function select(AutoBuild $build, mixed $families, mixed $choices, mixed $removed): void
    {
        $this->assertEditable($build);
        $synthesis = Synthesis::of(GameRecord::listFromJson($build->getGames()), $this->limits->autoFamilyMinGames);
        $selection = Selection::checked($families, $choices, $removed, array_column($synthesis['families'], 'key'));
        $build->select($selection->toArray());
        $this->entityManager->flush();
    }

    /**
     * The trees of the families kept, with the user's choices and removals.
     *
     * @return array<string, Tree> family key => tree, in the order of the selection
     */
    public function trees(AutoBuild $build): array
    {
        $selection = Selection::fromStored($build->getSelection());
        if ([] === $selection->families) {
            return [];
        }
        $byFamily = [];
        foreach (GameRecord::listFromJson($build->getGames()) as $game) {
            $byFamily[$game->familyKey()][] = $game;
        }
        $trees = [];
        foreach ($selection->families as $key) {
            $games = $byFamily[$key] ?? [];
            if ([] === $games) {
                continue;
            }
            $trees[$key] = $this->treeBuilder->build($games, $games[0]->color, $selection->choices[$key] ?? [], $selection->removed[$key] ?? []);
        }

        return $trees;
    }

    /**
     * The line ends of these trees Lichess was not asked about yet.
     *
     * @param array<string, Tree> $trees
     *
     * @return list<string> normalized FENs
     */
    public function pendingEvaluations(AutoBuild $build, array $trees): array
    {
        $known = $build->getEvaluations();
        $pending = [];
        foreach ($trees as $tree) {
            foreach ($tree->lines as $line) {
                if (!\array_key_exists($line['fen'], $known)) {
                    $pending[$line['fen']] = true;
                }
            }
        }

        return array_keys($pending);
    }

    /**
     * Asks the Lichess cloud about a few more line ends ({@see Limits::$autoEvaluationsPerRequest}).
     * What was learnt is kept when Lichess stops answering midway; nothing learnt: unavailable.
     *
     * @throws LichessUnavailableException
     */
    public function evaluate(AutoBuild $build): void
    {
        $pending = \array_slice($this->pendingEvaluations($build, $this->trees($build)), 0, $this->limits->autoEvaluationsPerRequest);
        $learnt = [];
        try {
            foreach ($pending as $fen) {
                $answer = $this->cloudEval->evaluate($fen, 1);
                $best = $answer['lines'][0] ?? null;
                $learnt[$fen] = $answer['found'] && null !== $best ? ['cp' => $best['cp'], 'mate' => $best['mate']] : null;
            }
        } catch (LichessUnavailableException $e) {
            if ([] === $learnt) {
                throw $e;
            }
        }
        if ([] !== $learnt) {
            $build->addEvaluations($learnt);
            $this->entityManager->flush();
        }
    }

    /**
     * Creates the repertoires of the families kept.
     *
     * @return list<string> their ids
     *
     * @throws AutoBuildException     not_ready, already_applied, nothing_selected
     * @throws LimitReachedException
     */
    public function apply(AutoBuild $build): array
    {
        $this->assertEditable($build);
        $trees = array_filter($this->trees($build), static fn (Tree $tree): bool => [] !== $tree->lines);
        if ([] === $trees) {
            throw new AutoBuildException(AutoBuildException::NOTHING_SELECTED);
        }
        $families = [];
        foreach (array_keys($trees) as $key) {
            $families[$key] = explode(':', $key, 2)[1] ?? $key;
        }
        $ids = $this->writer->write($build->getUser(), $build, $trees, $families);
        $build->applied($ids);
        $this->entityManager->flush();

        return $ids;
    }

    /**
     * @throws AutoBuildException
     */
    private function assertEditable(AutoBuild $build): void
    {
        match ($build->getStatus()) {
            AutoBuildStatus::Fetching => throw new AutoBuildException(AutoBuildException::NOT_READY),
            AutoBuildStatus::Applied => throw new AutoBuildException(AutoBuildException::ALREADY_APPLIED),
            AutoBuildStatus::Ready => null,
        };
    }

    /** The user's linked Lichess account (its id, lowercase), null when none is linked. */
    public static function lichessId(User $user): ?string
    {
        foreach ($user->getAuthIdentities() as $identity) {
            if (AuthProvider::Lichess === $identity->getProvider()) {
                return strtolower($identity->getProviderUserId());
            }
        }

        return null;
    }
}
