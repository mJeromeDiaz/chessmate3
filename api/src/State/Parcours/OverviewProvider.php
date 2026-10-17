<?php

declare(strict_types=1);

namespace App\State\Parcours;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Parcours\Overview;
use App\Enum\Parcours\Effort;
use App\Enum\Parcours\Theme;
use App\Parcours\Opening\RepertoireOpening;
use App\Parcours\ParcoursRules;
use App\Puzzle\Rating\RatingCalculator;
use App\Repository\Puzzle\RatingRepository;
use App\Repository\Repertoire\RepertoireRepository;
use App\Security\AuthenticatedUser;

/**
 * GET /parcours: the parcours' choices for the signed-in user.
 *
 * @implements ProviderInterface<Overview>
 */
final class OverviewProvider implements ProviderInterface
{
    public function __construct(
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RepertoireRepository $repertoires,
        private readonly RepertoireOpening $openings,
        private readonly RatingRepository $ratings,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Overview
    {
        $user = $this->authenticatedUser->get();
        $overview = new Overview();
        foreach (Theme::cases() as $theme) {
            $tiers = [];
            for ($tier = 0; $tier < ParcoursRules::TIERS; ++$tier) {
                $tiers[] = ParcoursRules::tier($theme, $tier);
            }
            $overview->themes[] = ['theme' => $theme->value, 'tiers' => $tiers];
        }
        foreach (Effort::cases() as $effort) {
            $overview->efforts[] = ['effort' => $effort->value, 'perTier' => $effort->perTier(), 'total' => $effort->perTier() * ParcoursRules::TIERS, 'bonusXp' => $effort->bonusXp()];
        }
        foreach ($this->repertoires->findByUser($user) as $repertoire) {
            $opening = $this->openings->of($repertoire);
            $overview->repertoires[] = [
                'id' => $repertoire->getId()->toRfc4122(),
                'name' => $repertoire->getName(),
                'color' => $repertoire->getColor()->value,
                'opening' => null === $opening ? null : ['family' => $opening['family'], 'name' => $opening['name']],
            ];
        }
        $overview->rating = (int) round($this->ratings->findOneByUser($user)?->getRating() ?? RatingCalculator::DEFAULT_RATING);
        $overview->ratingGap = ParcoursRules::RATING_GAP;

        return $overview;
    }
}
