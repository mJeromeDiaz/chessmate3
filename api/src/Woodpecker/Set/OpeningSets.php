<?php

declare(strict_types=1);

namespace App\Woodpecker\Set;

use App\Entity\User;
use App\Entity\Woodpecker\Set;
use App\Enum\Repertoire\Color;
use App\Puzzle\Opening\OpeningFamily;
use App\Puzzle\Selection\RandomSeeker;
use App\Repository\Woodpecker\SetRepository;
use App\Woodpecker\Exception\NotEnoughPuzzlesException;

/**
 * The opening sets of the tailored session (docs/COACH.md): a light set per colour, drawn from the
 * puzzles of the user's most played opening family with that colour, at the usual Woodpecker
 * difficulty ({@see SetManager::defaultRatingRange()}).
 *
 * INITIAL_SIZE puzzles where the player plays the user's colour; too few: of both sides; fewer
 * than MIN_SIZE even so: no set. The ongoing opening set of that colour is kept when it is of the
 * same family, abandoned (its history kept) for a new one otherwise.
 */
final class OpeningSets
{
    public const INITIAL_SIZE = 100;
    public const MIN_SIZE = 30;

    public function __construct(
        private readonly SetManager $manager,
        private readonly SetRepository $sets,
        private readonly RandomSeeker $seeker,
    ) {
    }

    /**
     * The ongoing opening set of this colour for this family, created if needed; null when the
     * catalogue has too few of its puzzles.
     *
     * @param string $family Lichess' opening family ("Italian Game")
     */
    public function ensure(User $user, string $family, Color $color): ?Set
    {
        $tag = OpeningFamily::tag($family);
        $ongoing = $this->sets->findOngoingOpening($user, $color);
        if (null !== $ongoing && $ongoing->getOpening()?->family === $tag) {
            return $ongoing;
        }

        [$min, $max] = $this->manager->defaultRatingRange($user);
        $bothColors = $this->seeker->countOpening($tag, $color->turn(), $min, $max) < self::INITIAL_SIZE;
        $available = $bothColors ? $this->seeker->countOpening($tag, null, $min, $max) : self::INITIAL_SIZE;
        if ('' === $tag || $available < self::MIN_SIZE) {
            return null;
        }

        if (null !== $ongoing) {
            $this->manager->abandon($user, $ongoing->getId());
        }
        $config = new LightConfig(min(self::INITIAL_SIZE, $available), $min, $max, [], false, new OpeningPool($tag, $color, $bothColors));
        try {
            return $this->manager->create($user, self::name($family, $color), $config);
        } catch (NotEnoughPuzzlesException) {
            // Counted, then drawn short (the random draws stop on a stale streak): none this time.
            return null;
        }
    }

    public static function name(string $family, Color $color): string
    {
        return mb_substr($family, 0, 64).' · '.(Color::White === $color ? 'Blancs' : 'Noirs');
    }
}
