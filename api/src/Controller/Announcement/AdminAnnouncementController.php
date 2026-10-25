<?php

declare(strict_types=1);

namespace App\Controller\Announcement;

use App\Announcement\Admin\AnnouncementEditor;
use App\Announcement\Admin\AnnouncementViews;
use App\Entity\Announcement\Announcement;
use App\Entity\User;
use App\Security\RateLimit\RateLimitGuard;
use App\Shop\Admin\InvalidPayload;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The announcements' administration (docs/ANNOUNCEMENTS.md, § 2), admins only: list, write
 * (draft or published at once), archive, and the email to the active accounts asked once. Writes
 * within the admin's budget (admin_write); a form refused answers 422 `{violations}`.
 */
#[Route('/api/admin/announcements')]
#[IsGranted('ROLE_ADMIN')]
final class AdminAnnouncementController extends AbstractController
{
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(
        private readonly AnnouncementEditor $editor,
        private readonly AnnouncementViews $views,
        private readonly EntityManagerInterface $entityManager,
        private readonly RateLimitGuard $rateLimitGuard,
        #[Autowire(service: 'limiter.admin_write')]
        private readonly RateLimiterFactory $adminWriteLimiter,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'admin_announcement_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(['announcements' => $this->views->list()]);
    }

    /** The accounts an email asked now would serve (for the confirmation). */
    #[Route('/email-recipients', name: 'admin_announcement_email_recipients', methods: ['GET'])]
    public function recipients(): JsonResponse
    {
        return $this->json(['count' => $this->views->recipients()]);
    }

    /** POST {titleFr, bodyFr, titleEn?, bodyEn?, startsAt?, endsAt?, publish?} (201). */
    #[Route('', name: 'admin_announcement_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $admin): JsonResponse
    {
        return $this->write($admin, fn (): JsonResponse => $this->json(
            $this->views->one($this->editor->create($request->toArray(), $admin)),
            Response::HTTP_CREATED,
        ));
    }

    /** PUT the same fields, all replaced; `publish` true publishes a draft. */
    #[Route('/{id}', name: 'admin_announcement_update', requirements: ['id' => self::UUID], methods: ['PUT'])]
    public function update(string $id, Request $request, #[CurrentUser] User $admin): JsonResponse
    {
        return $this->write($admin, fn (): JsonResponse => null === ($announcement = $this->find($id))
            ? $this->notFound()
            : $this->json($this->views->one($this->editor->update($announcement, $request->toArray()))));
    }

    #[Route('/{id}/archive', name: 'admin_announcement_archive', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function archive(string $id, #[CurrentUser] User $admin): JsonResponse
    {
        return $this->write($admin, fn (): JsonResponse => null === ($announcement = $this->find($id))
            ? $this->notFound()
            : $this->json($this->views->one($this->editor->archive($announcement))));
    }

    /** The email to the active accounts, once; it waits for the start of a scheduled announcement. */
    #[Route('/{id}/email', name: 'admin_announcement_email', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function email(string $id, #[CurrentUser] User $admin): JsonResponse
    {
        return $this->write($admin, fn (): JsonResponse => null === ($announcement = $this->find($id))
            ? $this->notFound()
            : $this->json($this->views->one($this->editor->requestEmail($announcement))));
    }

    private function find(string $id): ?Announcement
    {
        return $this->entityManager->find(Announcement::class, Uuid::fromString($id));
    }

    private function notFound(): JsonResponse
    {
        return $this->json(['detail' => 'Announcement not found.'], Response::HTTP_NOT_FOUND);
    }

    /**
     * @param \Closure(): JsonResponse $write
     */
    private function write(User $admin, \Closure $write): JsonResponse
    {
        $this->rateLimitGuard->consume($this->adminWriteLimiter, $admin->getId()->toRfc4122());
        try {
            return $write();
        } catch (InvalidPayload $invalid) {
            // In the language of the admin's request (docs/I18N.md, § 5).
            return $this->json(['violations' => array_map(fn (array $violation): array => [
                'propertyPath' => $violation['propertyPath'],
                'title' => $this->translator->trans($violation['message'], $violation['parameters'], 'admin'),
            ], $invalid->violations)], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Symfony\Component\HttpFoundation\Exception\JsonException) {
            return $this->json(['detail' => 'Invalid JSON.'], Response::HTTP_BAD_REQUEST);
        }
    }
}
