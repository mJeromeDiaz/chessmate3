<?php

declare(strict_types=1);

namespace App\Controller\Announcement;

use App\Announcement\Display\CurrentAnnouncements;
use App\Announcement\Email\UnsubscribeToken;
use App\Entity\User;
use App\I18n\UserLocale;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * The team's announcements on the player's side (docs/ANNOUNCEMENTS.md, § 3 and § 5): those still
 * to see, closing one, and the email's "stop these emails" link, which needs no session.
 */
#[Route('/api/announcements')]
final class AnnouncementController extends AbstractController
{
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(
        private readonly CurrentAnnouncements $current,
        private readonly UserLocale $userLocale,
        private readonly UnsubscribeToken $tokens,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** `{announcements: [{id, title, body, startsAt}]}`, the oldest first, in the player's language. */
    #[Route('', name: 'app_announcement_current', methods: ['GET'])]
    public function current(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json(['announcements' => $this->current->of($user, $this->userLocale->of($user))]);
    }

    /** The player closed the modal: never shown again (204, an unknown id included). */
    #[Route('/{id}/read', name: 'app_announcement_read', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function read(string $id, #[CurrentUser] User $user): Response
    {
        $this->current->markRead(Uuid::fromString($id), $user);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Turns the announcement emails off (204), public: `u` (the account id) and `t` (its token) in
     * the query string, as the one-click List-Unsubscribe POST of a mail client sends them
     * (RFC 8058), or in a JSON body from the SPA's page. A wrong pair answers 400.
     */
    #[Route('/unsubscribe', name: 'app_announcement_unsubscribe', methods: ['POST'])]
    public function unsubscribe(Request $request): Response
    {
        $userId = $request->query->getString('u');
        $token = $request->query->getString('t');
        if ('' === $userId && 'json' === $request->getContentTypeFormat()) {
            try {
                $body = $request->toArray();
            } catch (\Symfony\Component\HttpFoundation\Exception\JsonException) {
                $body = [];
            }
            $userId = \is_string($body['u'] ?? null) ? $body['u'] : '';
            $token = \is_string($body['t'] ?? null) ? $body['t'] : '';
        }
        if (!Uuid::isValid($userId) || !$this->tokens->isValid(Uuid::fromString($userId), $token)) {
            return $this->json(['detail' => 'Invalid link.'], Response::HTTP_BAD_REQUEST);
        }
        $user = $this->users->find(Uuid::fromString($userId));
        if (null !== $user && $user->hasAnnouncementEmails()) {
            $user->setAnnouncementEmails(false);
            $this->entityManager->flush();
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
