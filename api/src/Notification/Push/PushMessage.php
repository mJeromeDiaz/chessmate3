<?php

declare(strict_types=1);

namespace App\Notification\Push;

/**
 * A notification shown by the service worker (`front/public/push-sw.js`): title, text, the SPA
 * route opened on click, a tag (a newer one with the same tag replaces it) and how long the push
 * service may keep it for an offline browser.
 */
final readonly class PushMessage
{
    public function __construct(
        public string $title,
        public string $body,
        public string $url,
        public string $tag,
        public int $ttlSeconds,
    ) {
    }

    public function payload(): string
    {
        return json_encode(['title' => $this->title, 'body' => $this->body, 'url' => $this->url, 'tag' => $this->tag], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }
}
