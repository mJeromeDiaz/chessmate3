<?php

declare(strict_types=1);

namespace App\Challenge\Path;

use App\Entity\Repertoire\Repertoire;
use App\Entity\User;
use App\Enum\MindMap\Capacity;
use App\Enum\Repertoire\TestUnit;
use App\MindMap\Path\Challenges;
use App\MindMap\Path\Chapter;
use App\Parcours\Opening\RepertoireOpening;
use App\Repertoire\Training\Scope;
use App\Repertoire\Training\UnitCatalog;
use App\Repository\MindMap\ChestRepository;
use App\Repository\Repertoire\RepertoireRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The défis' opening chapters of a player (docs/DEFIS.md): one per repertoire that has a line to
 * review and an opening with enough puzzles for its side ({@see RepertoireOpening}), oldest
 * first, after the fixed chapters. A repertoire removed takes its chapter away (its progress
 * stays, unseen).
 */
final readonly class OpeningChapters
{
    public function __construct(
        private RepertoireRepository $repertoires,
        private RepertoireOpening $openings,
        private ChestRepository $chests,
        private UnitCatalog $units,
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The player's opening chapters, their lines not counted (a pass of {@see Challenges::PASS_COUNT}).
     *
     * @return list<Chapter>
     */
    public function of(User $user): array
    {
        $paid = $this->chests->paidRewardKeys($user, Capacity::Challenges);
        $number = \count(Challenges::chapters());
        $chapters = [];
        $repertoires = $this->repertoires->findByUser($user);
        // Oldest first; ids (time-ordered) apart those of the same second.
        usort($repertoires, static fn (Repertoire $a, Repertoire $b): int => [$a->getCreatedAt(), $a->getId()->toRfc4122()] <=> [$b->getCreatedAt(), $b->getId()->toRfc4122()]);
        foreach ($repertoires as $repertoire) {
            $chapter = $this->chapter($repertoire, $number + 1, $paid, false);
            if (null !== $chapter) {
                $chapters[] = $chapter;
                ++$number;
            }
        }

        return $chapters;
    }

    /**
     * The opening chapter of a step's key, its lines counted (what a pass plays and needs), null
     * when the key is no opening chapter's of the player (any more). Its number is not the
     * page's (0).
     */
    public function find(User $user, string $key): ?Chapter
    {
        $opening = Challenges::parseOpening($key);
        $repertoire = null === $opening ? null : $this->repertoires->findOwned($opening['repertoireId'], $user);

        return null === $repertoire ? null : $this->chapter($repertoire, 0, $this->chests->paidRewardKeys($user, Capacity::Challenges), true);
    }

    /**
     * @param list<string> $paid the reward keys already paid
     */
    private function chapter(Repertoire $repertoire, int $number, array $paid, bool $countLines): ?Chapter
    {
        if (!$this->hasLines($repertoire->getId())) {
            return null;
        }
        $opening = $this->openings->of($repertoire);
        if (null === $opening) {
            return null;
        }

        return Challenges::opening(Challenges::code($repertoire->getId()), $number, [
            'repertoireId' => $repertoire->getId()->toRfc4122(),
            'family' => $opening['family'],
            'color' => $opening['color'],
            'lines' => $countLines ? $this->lines($repertoire) : null,
            'paid' => \in_array(Challenges::rewardKey($opening['family'], $opening['color']), $paid, true),
        ], [
            'repertoireId' => $repertoire->getId()->toRfc4122(),
            'name' => $repertoire->getName(),
            'color' => $repertoire->getColor()->value,
            'opening' => $opening['name'],
            'family' => $opening['family'],
        ]);
    }

    /** A line to review: a position with its prepared move. */
    private function hasLines(Uuid $repertoireId): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM repertoire_position WHERE repertoire_id = ? LIMIT 1 OFFSET 1',
            [$repertoireId->toBinary()],
            [ParameterType::BINARY],
        );
    }

    /** The lines a test of the repertoire plays, at most a pass's. */
    private function lines(Repertoire $repertoire): int
    {
        $scope = new Scope([$repertoire->getId()->toRfc4122()], TestUnit::Line);

        return min(Challenges::PASS_COUNT, \count($this->units->standings([$repertoire], $scope, $this->clock->now())));
    }
}
