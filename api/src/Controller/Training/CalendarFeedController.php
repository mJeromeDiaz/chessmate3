<?php

declare(strict_types=1);

namespace App\Controller\Training;

use App\Repository\Training\PlanRepository;
use App\Security\RateLimit\RateLimitGuard;
use App\Training\Calendar\FeedTokens;
use App\Training\Calendar\IcsWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /api/calendar/{token}.ics: the saved sessions a user put in their calendar, for calendar
 * apps that subscribe to it (docs/TRAINING.md, calendar). Public: the secret token is the only
 * credential (calendar apps send no other). Unknown or revoked token: 404. Rate-limited per token
 * (not per IP: Google or Apple fetch every feed from the same servers).
 */
final class CalendarFeedController extends AbstractController
{
    public function __construct(
        private readonly FeedTokens $tokens,
        private readonly PlanRepository $plans,
        private readonly IcsWriter $writer,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $trainingCalendarFeedLimiter,
    ) {
    }

    #[Route('/api/calendar/{token}.ics', name: 'training_calendar_feed', requirements: ['token' => FeedTokens::PATTERN], methods: ['GET', 'HEAD'])]
    public function __invoke(string $token): Response
    {
        $this->rateLimitGuard->consume($this->trainingCalendarFeedLimiter, FeedTokens::hash($token));
        $user = $this->tokens->owner($token) ?? throw $this->createNotFoundException();

        return new Response($this->writer->calendar($user, $this->plans->findInCalendar($user), 'ChessMate — sessions'), Response::HTTP_OK, [
            'Content-Type' => 'text/calendar; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="chessmate.ics"',
            'Cache-Control' => 'private, no-cache',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
