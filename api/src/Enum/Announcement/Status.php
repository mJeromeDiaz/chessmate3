<?php

declare(strict_types=1);

namespace App\Enum\Announcement;

/**
 * Where an announcement stands (docs/ANNOUNCEMENTS.md): a draft is shown to no one, a published
 * one between its start and its end, an archived one never again. Stored as its value: add cases,
 * never rename one.
 */
enum Status: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
