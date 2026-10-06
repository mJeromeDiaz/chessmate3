<?php

declare(strict_types=1);

namespace App\Controller\Training;

use App\ApiResource\Woodpecker\Set;
use App\Entity\User;
use App\Enum\Training\Repetition;
use App\Repository\Training\PlanRepository;
use App\Security\RateLimit\RateLimitGuard;
use App\Training\Calendar\FeedTokens;
use App\Training\Calendar\IcsWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * The user's calendar (docs/TRAINING.md, calendar): their private feed address (read, regenerate,
 * revoke) and one saved session as an .ics file. Plain controllers: the answers are an address
 * and a file, not resources.
 */
final class CalendarController extends AbstractController
{
    public function __construct(
        private readonly FeedTokens $tokens,
        private readonly PlanRepository $plans,
        private readonly IcsWriter $writer,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $trainingCalendarWriteLimiter,
    ) {
    }

    /** `{url, webcalUrl}`, both null without an address. */
    #[Route('/api/training/calendar', name: 'training_calendar', methods: ['GET'])]
    public function show(#[CurrentUser] User $user): JsonResponse
    {
        return $this->address($this->tokens->current($user));
    }

    /** A new address: the previous one (if any) stops working. */
    #[Route('/api/training/calendar', name: 'training_calendar_regenerate', methods: ['POST'])]
    public function regenerate(#[CurrentUser] User $user): JsonResponse
    {
        $this->rateLimitGuard->consume($this->trainingCalendarWriteLimiter, $user->getId()->toRfc4122());

        return $this->address($this->tokens->regenerate($user));
    }

    #[Route('/api/training/calendar', name: 'training_calendar_revoke', methods: ['DELETE'])]
    public function revoke(#[CurrentUser] User $user): Response
    {
        $this->rateLimitGuard->consume($this->trainingCalendarWriteLimiter, $user->getId()->toRfc4122());
        $this->tokens->revoke($user);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** One repeated session, to import once (it won't follow later changes). 404 on demand. */
    #[Route('/api/training/plans/{id}/calendar.ics', name: 'training_plan_ics', requirements: ['id' => Set::UUID_PATTERN], methods: ['GET'])]
    public function plan(string $id, #[CurrentUser] User $user): Response
    {
        $plan = $this->plans->findOwned(Uuid::fromString($id), $user);
        if (null === $plan || Repetition::OnDemand === $plan->getRepetition()) {
            throw $this->createNotFoundException();
        }

        return new Response($this->writer->calendar($user, [$plan], '' !== $plan->getTitle() ? $plan->getTitle() : 'Session Don\'t Stay Rooky'), Response::HTTP_OK, [
            'Content-Type' => 'text/calendar; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, IcsWriter::fileName($plan)),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function address(?string $token): JsonResponse
    {
        $url = null !== $token
            ? $this->generateUrl('training_calendar_feed', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL)
            : null;

        return $this->json([
            'url' => $url,
            'webcalUrl' => null !== $url ? (string) preg_replace('#^https?://#', 'webcal://', $url) : null,
        ]);
    }
}
