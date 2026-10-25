<?php

declare(strict_types=1);

namespace App\Tests\Unit\Announcement;

use App\Announcement\Email\PlainText;
use PHPUnit\Framework\TestCase;

/**
 * The text part of an announcement's email (docs/ANNOUNCEMENTS.md, § 4).
 */
final class PlainTextTest extends TestCase
{
    public function testParagraphsListsAndLinksBecomeLines(): void
    {
        $html = '<h2>Nouveau</h2><p>Le module <b>Brillant&nbsp;ou Blunder</b> est là&#8239;!</p>'
            .'<ul><li>2 boutons</li><li>1 à 10 min</li></ul>'
            .'<div>Essaie-le <a href="https://example.com/a?b=1&amp;c=2" target="_blank" rel="noopener noreferrer">ici</a>.<br>Merci</div>';

        self::assertSame(
            "Nouveau\nLe module Brillant ou Blunder est là\u{202F}!\n- 2 boutons\n- 1 à 10 min\n\nEssaie-le ici (https://example.com/a?b=1&c=2).\nMerci",
            PlainText::of($html),
        );
    }

    public function testATextWithoutTagsStaysAsIs(): void
    {
        self::assertSame('Bonjour à tous', PlainText::of('  Bonjour à tous '));
    }
}
