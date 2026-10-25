<?php

declare(strict_types=1);

namespace App\Announcement\Email;

/**
 * The text part of an announcement's email (docs/ANNOUNCEMENTS.md, § 4): its sanitized HTML as
 * plain text, a line per paragraph or list item, each link followed by its address.
 */
final class PlainText
{
    public static function of(string $html): string
    {
        $text = preg_replace_callback(
            '#<a\b[^>]*\bhref="([^"]*)"[^>]*>(.*?)</a>#is',
            static fn (array $link): string => trim(strip_tags($link[2])).' ('.html_entity_decode($link[1], \ENT_QUOTES | \ENT_HTML5).')',
            $html,
        ) ?? $html;
        $text = preg_replace('#<li\b[^>]*>#i', '- ', $text) ?? $text;
        $text = preg_replace('#<br\s*/?>|</(p|div|li|h2|h3|h4|blockquote|ul|ol)>#i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), \ENT_QUOTES | \ENT_HTML5);
        $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/ *\n */", "\n", $text) ?? $text;

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }
}
