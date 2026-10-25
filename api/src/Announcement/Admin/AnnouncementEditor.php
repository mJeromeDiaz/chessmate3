<?php

declare(strict_types=1);

namespace App\Announcement\Admin;

use App\Announcement\Email\EmailDispatcher;
use App\Entity\Announcement\Announcement;
use App\Entity\User;
use App\Enum\Announcement\Status;
use App\Shop\Admin\InvalidPayload;
use App\Shop\Admin\Payload;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Writes the announcements of the admin page (docs/ANNOUNCEMENTS.md): the texts (the HTML sanitized
 * here, once, then shown as is), the optional start and end, publishing, archiving, and the email
 * asked once (queued by {@see EmailDispatcher}). A form refused throws {@see InvalidPayload}, its
 * messages keys of the "admin" domain.
 */
final readonly class AnnouncementEditor
{
    /** The HTML as received, before sanitizing. */
    public const BODY_LENGTH = 20_000;

    public function __construct(
        private EntityManagerInterface $entityManager,
        #[Autowire(service: 'html_sanitizer.sanitizer.announcement')]
        private HtmlSanitizerInterface $sanitizer,
        private EmailDispatcher $emails,
        private ClockInterface $clock,
    ) {
    }

    /**
     * {titleFr, bodyFr, titleEn?, bodyEn?, startsAt?, endsAt?, publish?}: a draft, or published
     * at once when `publish` is true.
     *
     * @param array<mixed> $data
     *
     * @throws InvalidPayload
     */
    public function create(array $data, User $admin): Announcement
    {
        $now = $this->clock->now();
        $payload = new Payload($data);
        [$titleFr, $bodyFr, $titleEn, $bodyEn, $startsAt, $endsAt] = $this->read($payload, $now);
        $publish = $payload->bool('publish');
        $payload->done();

        $announcement = new Announcement((string) $titleFr, (string) $bodyFr, $admin, $now);
        $announcement->write((string) $titleFr, (string) $bodyFr, $titleEn, $bodyEn, $now)->schedule($startsAt, $endsAt);
        if ($publish) {
            $announcement->publish($now);
        }
        $this->entityManager->persist($announcement);
        $this->entityManager->flush();

        return $announcement;
    }

    /**
     * The same fields, every one replaced; `publish` true publishes a draft (a published one
     * stays published).
     *
     * @param array<mixed> $data
     *
     * @throws InvalidPayload
     */
    public function update(Announcement $announcement, array $data): Announcement
    {
        $now = $this->clock->now();
        $payload = new Payload($data);
        if (Status::Archived === $announcement->getStatus()) {
            $payload->fail('status', 'announcement.archived');
        }
        [$titleFr, $bodyFr, $titleEn, $bodyEn, $startsAt, $endsAt] = $this->read($payload, $now);
        $publish = $payload->bool('publish');
        $payload->done();

        $announcement->write((string) $titleFr, (string) $bodyFr, $titleEn, $bodyEn, $now)->schedule($startsAt, $endsAt);
        if ($publish) {
            $announcement->publish($now);
        }
        $this->entityManager->flush();

        return $announcement;
    }

    public function archive(Announcement $announcement): Announcement
    {
        $announcement->archive($this->clock->now());
        $this->entityManager->flush();

        return $announcement;
    }

    /**
     * The email to the active accounts, asked once: queued from the start on, a few recipients
     * each minute ({@see EmailDispatcher}), the first ones at once when already started.
     *
     * @throws InvalidPayload
     */
    public function requestEmail(Announcement $announcement): Announcement
    {
        $now = $this->clock->now();
        $payload = new Payload([]);
        if (Status::Published !== $announcement->getStatus()) {
            $payload->fail('email', 'announcement.not_published');
        } elseif ($announcement->hasEnded($now)) {
            $payload->fail('email', 'announcement.ended');
        } elseif (null !== $announcement->getEmailRequestedAt()) {
            $payload->fail('email', 'announcement.email_already');
        }
        $payload->done();

        $announcement->requestEmail($now);
        $this->entityManager->flush();
        $this->emails->dispatchDue();

        return $announcement;
    }

    /**
     * @return array{?string, ?string, ?string, ?string, ?\DateTimeImmutable, ?\DateTimeImmutable}
     */
    private function read(Payload $payload, \DateTimeImmutable $now): array
    {
        $titleFr = $payload->string('titleFr', Announcement::TITLE_LENGTH);
        $bodyFr = $this->body($payload, 'bodyFr', true);
        $titleEn = $payload->string('titleEn', Announcement::TITLE_LENGTH, false);
        $bodyEn = $this->body($payload, 'bodyEn', false);
        if ((null === $titleEn) !== (null === $bodyEn)) {
            $payload->fail(null === $titleEn ? 'titleEn' : 'bodyEn', 'announcement.english_pair');
        }
        $startsAt = $payload->instant('startsAt');
        $endsAt = $payload->instant('endsAt');
        if (null !== $endsAt) {
            if (null !== $startsAt && $endsAt <= $startsAt) {
                $payload->fail('endsAt', 'announcement.ends_after_start');
            } elseif ($endsAt <= $now) {
                $payload->fail('endsAt', 'announcement.ends_in_past');
            }
        }

        return [$titleFr, $bodyFr, $titleEn, $bodyEn, $startsAt, $endsAt];
    }

    /** The HTML sanitized; null when empty (no text left: an empty editor sends "<br>"). */
    private function body(Payload $payload, string $field, bool $required): ?string
    {
        $html = $payload->string($field, self::BODY_LENGTH, $required);
        if (null === $html) {
            return null;
        }
        $clean = trim($this->sanitizer->sanitize($html));
        if ('' === preg_replace('/[\s\x{00A0}]+/u', '', html_entity_decode(strip_tags($clean), \ENT_QUOTES | \ENT_HTML5))) {
            if ($required) {
                $payload->fail($field, 'announcement.empty_body');
            }

            return null;
        }

        return $clean;
    }
}
