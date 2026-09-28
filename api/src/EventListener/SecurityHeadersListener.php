<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds baseline security headers to every response.
 *
 * This is a JSON API with no first-party HTML rendering, so the CSP is intentionally as strict as
 * possible: nothing is ever allowed to load, script, style, frame, or navigate. Priority -200 makes
 * this run after Symfony's {@see \Symfony\Component\HttpKernel\EventListener\ErrorListener}, which
 * strips the CSP header on exception-converted responses (a dev-toolbar convenience) — an API has no
 * HTML error page for that to matter for, and an error response deserves the same CSP as any other.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'onKernelResponse', priority: -200)]
final class SecurityHeadersListener
{
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set(
            'Content-Security-Policy',
            "default-src 'none'; frame-ancestors 'none'; base-uri 'none'"
        );
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set(
            'Strict-Transport-Security',
            'max-age=63072000; includeSubDomains; preload'
        );
    }
}
