<?php

declare(strict_types=1);

namespace App\Security\Trace;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Symfony\Component\Security\Http\AccessMapInterface;

/**
 * Writes one line per request to the `trace` log channel (var/log/traces, one file a day, kept 365
 * days: config/packages/monolog.yaml), for investigating after an incident (docs/SECURITY.md,
 * § 2.12).
 *
 * Traced: every /api/auth route, and every route that security.yaml does not open to everyone
 * (read from the access map, so a new protected route is traced without touching this class).
 * Never traced: the raw access token (a short fingerprint and its iat/exp instead), the refresh
 * cookie, and the secrets of the body and the query string ({@see Redactor}).
 *
 * Written on kernel.terminate, after the response has been sent; a failure here never reaches the
 * player.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onRequest', priority: 4096)]
#[AsEventListener(event: KernelEvents::TERMINATE, method: 'onTerminate')]
final class RequestTraceListener
{
    /** Bodies longer than this, once redacted and encoded, are cut (a PGN import can weigh megabytes). */
    public const MAX_BODY_BYTES = 4096;

    private const START_ATTRIBUTE = '_trace_start';
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
    private const BODY_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        #[Autowire(service: 'monolog.logger.trace')]
        private readonly LoggerInterface $traceLogger,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'security.access_map')]
        private readonly AccessMapInterface $accessMap,
        private readonly Security $security,
        private readonly Redactor $redactor,
    ) {
    }

    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getRequest()->attributes->set(self::START_ATTRIBUTE, microtime(true));
        }
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();

        try {
            if (!$this->isTraced($request)) {
                return;
            }

            $status = $event->getResponse()->getStatusCode();
            $this->traceLogger->info(
                \sprintf('%s %s %d', $request->getMethod(), $request->getPathInfo(), $status),
                $this->context($request, $status),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Request trace failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
        }
    }

    private function isTraced(Request $request): bool
    {
        if (!\in_array($request->getMethod(), self::METHODS, true)) {
            return false;
        }

        $path = $request->getPathInfo();
        if ('/api/auth' === $path || str_starts_with($path, '/api/auth/')) {
            return true;
        }

        [$attributes] = $this->accessMap->getPatterns($request);

        return null !== $attributes && [] !== $attributes
            && !\in_array(AuthenticatedVoter::PUBLIC_ACCESS, $attributes, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Request $request, int $status): array
    {
        $start = $request->attributes->get(self::START_ATTRIBUTE);
        // The user is only read where the firewall already authenticated the request: on a public
        // route, asking for it would make the lazy firewall authenticate now, after the response.
        $user = $this->isPublic($request) ? null : $this->security->getUser();
        $userId = $user instanceof User ? $user->getId()->toRfc4122() : null;

        return [
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'query' => [] === $request->query->all() ? null : $this->redactor->redact($request->query->all()),
            'status' => $status,
            'durationMs' => \is_float($start) ? (int) round((microtime(true) - $start) * 1000) : null,
            'ip' => $request->getClientIp(),
            'userAgent' => mb_substr((string) $request->headers->get('User-Agent', ''), 0, 255),
            'userId' => $userId,
            'token' => $this->token($request, null !== $userId),
        ] + $this->body($request);
    }

    private function isPublic(Request $request): bool
    {
        [$attributes] = $this->accessMap->getPatterns($request);

        return null === $attributes || \in_array(AuthenticatedVoter::PUBLIC_ACCESS, $attributes, true);
    }

    /**
     * A fingerprint of the bearer token (the same for every request of one access token, useless to
     * sign in) and, once the firewall has verified its signature, its issue and expiry times.
     *
     * @return array{fingerprint: string, iat: int|null, exp: int|null}|null
     */
    private function token(Request $request, bool $verified): ?array
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $jwt = trim(substr($header, 7));
        if ('' === $jwt) {
            return null;
        }

        $claims = $verified ? $this->claims($jwt) : [];

        return [
            'fingerprint' => substr(hash('sha256', $jwt), 0, 16),
            'iat' => \is_int($claims['iat'] ?? null) ? $claims['iat'] : null,
            'exp' => \is_int($claims['exp'] ?? null) ? $claims['exp'] : null,
        ];
    }

    /**
     * @return array<mixed>
     */
    private function claims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (3 !== \count($parts)) {
            return [];
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $claims = false === $payload ? null : json_decode($payload, true);

        return \is_array($claims) ? $claims : [];
    }

    /**
     * The redacted body of a write (form fields, uploaded files by name and size, or JSON), cut at
     * {@see MAX_BODY_BYTES}. A body that is not JSON is only measured: its content could be anything.
     *
     * @return array<string, mixed>
     */
    private function body(Request $request): array
    {
        if (!\in_array($request->getMethod(), self::BODY_METHODS, true)) {
            return [];
        }

        $content = $request->getContent();
        $files = $this->files($request->files->all());

        if ([] !== $request->request->all() || [] !== $files) {
            $data = $request->request->all() + ([] === $files ? [] : ['_files' => $files]);
        } elseif ('' === $content) {
            return ['body' => null];
        } else {
            $data = json_decode($content, true);
            if (!\is_array($data)) {
                return ['body' => \sprintf('[%d bytes, not JSON]', \strlen($content))];
            }
        }

        $redacted = $this->redactor->redact($data);
        $encoded = json_encode(
            $redacted,
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR,
        );

        if (\strlen($encoded) <= self::MAX_BODY_BYTES) {
            return ['body' => $redacted];
        }

        return [
            'body' => mb_strcut($encoded, 0, self::MAX_BODY_BYTES),
            'bodyTruncated' => true,
            'bodyBytes' => \strlen('' === $content ? $encoded : $content),
        ];
    }

    /**
     * @param array<mixed> $files
     *
     * @return array<mixed>
     */
    private function files(array $files): array
    {
        $described = [];
        foreach ($files as $key => $file) {
            if ($file instanceof UploadedFile) {
                $described[$key] = ['name' => $file->getClientOriginalName(), 'size' => $file->getSize()];
            } elseif (\is_array($file)) {
                $described[$key] = $this->files($file);
            }
        }

        return $described;
    }
}
