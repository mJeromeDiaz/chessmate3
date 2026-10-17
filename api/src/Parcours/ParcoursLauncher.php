<?php

declare(strict_types=1);

namespace App\Parcours;

use App\Entity\Training\Session;
use App\Entity\User;
use App\Enum\Parcours\Effort;
use App\Enum\Parcours\Theme;
use App\Enum\Repertoire\TestUnit;
use App\Enum\Training\Module;
use App\I18n\UserLocale;
use App\Parcours\Opening\RepertoireOpening;
use App\Repository\Repertoire\RepertoireRepository;
use App\Training\Exception\InvalidSessionException;
use App\Training\Exception\SessionInProgressException;
use App\Training\Exception\SubjectNotFoundException;
use App\Training\Session\SessionManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Launches a parcours (docs/PARCOURS.md): a session whose five steps are its tiers, ended by their
 * counts (the effort's), resumable until its local midnight; its first tier is started by the
 * session's `next`, as any session. Title and description in the player's language.
 */
final readonly class ParcoursLauncher
{
    /** Unused by a parcours tier (ended by its count): only the step format's. */
    private const STEP_MINUTES = 60;

    public function __construct(
        private SessionManager $sessions,
        private RepertoireRepository $repertoires,
        private RepertoireOpening $openings,
        private Connection $connection,
        private TranslatorInterface $translator,
        private UserLocale $userLocale,
    ) {
    }

    /**
     * @throws SubjectNotFoundException     the repertoire is not the user's (or missing for an opening)
     * @throws ParcoursUnavailableException
     * @throws InvalidSessionException
     * @throws SessionInProgressException
     */
    public function launch(User $user, Theme $theme, Effort $effort, ?Uuid $repertoireId): Session
    {
        $count = $effort->perTier();
        $locale = $this->userLocale->of($user);
        $opening = null;
        $repertoire = null;
        if (Theme::Opening === $theme) {
            $repertoire = (null === $repertoireId ? null : $this->repertoires->findOwned($repertoireId, $user)) ?? throw new SubjectNotFoundException();
            if (!$this->hasLines($repertoire->getId())) {
                throw new ParcoursUnavailableException(ParcoursUnavailableException::EMPTY_REPERTOIRE);
            }
            $opening = $this->openings->of($repertoire) ?? throw new ParcoursUnavailableException(ParcoursUnavailableException::NO_OPENING);
        }

        $steps = [];
        for ($tier = 0; $tier < ParcoursRules::TIERS; ++$tier) {
            $plan = ParcoursRules::tier($theme, $tier);
            $steps[] = ['module' => ParcoursRules::LINES === $plan['kind'] ? Module::Repertoire : Module::ParcoursPuzzles, 'minutes' => self::STEP_MINUTES, 'notes' => '', 'settings' => match (true) {
                ParcoursRules::LINES === $plan['kind'] && null !== $repertoire => ['repertoireIds' => [$repertoire->getId()->toRfc4122()], 'unit' => TestUnit::Line->value, 'limit' => $count],
                null !== $opening => ['tier' => $tier, 'family' => $opening['family'], 'color' => $opening['color'], 'count' => $count],
                default => ['tier' => $tier, 'themes' => $plan['themes'], 'count' => $count],
            }];
        }

        $title = null === $repertoire
            ? $this->translator->trans('title', ['%theme%' => $this->translator->trans('theme.'.$theme->value, [], 'parcours', $locale)], 'parcours', $locale)
            : $this->translator->trans('title_opening', ['%repertoire%' => $repertoire->getName()], 'parcours', $locale);
        $description = $this->translator->trans('description', [
            '%effort%' => $this->translator->trans('effort.'.$effort->value, [], 'parcours', $locale),
            '%count%' => $count * ParcoursRules::TIERS,
        ], 'parcours', $locale);

        return $this->sessions->create($user, mb_substr($title, 0, 120), $description, $steps, null, [
            'theme' => $theme->value,
            'effort' => $effort->value,
            'repertoireId' => $repertoire?->getId()->toRfc4122(),
        ]);
    }

    /** A line to review: a position with its prepared move. */
    private function hasLines(Uuid $repertoireId): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM repertoire_position WHERE repertoire_id = ? LIMIT 2 OFFSET 1',
            [$repertoireId->toBinary()],
            [ParameterType::BINARY],
        );
    }
}
