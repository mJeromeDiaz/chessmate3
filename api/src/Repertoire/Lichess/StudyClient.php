<?php

declare(strict_types=1);

namespace App\Repertoire\Lichess;

use App\Entity\User;
use App\Repertoire\Limits;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The PGN of a Lichess study, or of one of its chapters (GET /api/study/{id}.pgn and
 * /api/study/{id}/{chapterId}.pgn), through {@see LichessGateway}: comments, variations and the
 * Orientation tag, no clocks. Anonymous, Lichess gives public studies only; with the user's own
 * token granted study:read ({@see TokenResolver::studyToken()}), their private and unlisted ones
 * too. docs/REPERTOIRE.md, "Import".
 */
final readonly class StudyClient
{
    /** lichess.org/study/{8 chars}[/{8 chars}], with or without scheme, www, a query or a fragment. */
    private const URL = '#^(?:https?://)?(?:www\.)?lichess\.org/study/([A-Za-z0-9]{8})(?:/([A-Za-z0-9]{8}))?/?(?:[?\#].*)?$#';

    public function __construct(
        private HttpClientInterface $lichessApiClient,
        private LichessGateway $gateway,
        private TokenResolver $tokens,
        private Limits $limits,
    ) {
    }

    /**
     * @return array{string, string|null}|null study id and chapter id, null when not a study URL
     */
    public static function parse(string $url): ?array
    {
        if (1 !== preg_match(self::URL, trim($url), $m)) {
            return null;
        }

        return [$m[1], '' !== ($m[2] ?? '') ? $m[2] : null];
    }

    /**
     * @throws StudyUnavailableException
     * @throws LichessUnavailableException
     */
    public function pgn(User $user, string $studyId, ?string $chapterId): string
    {
        $token = $this->tokens->studyToken($user);
        $path = '/api/study/'.$studyId.(null === $chapterId ? '' : '/'.$chapterId).'.pgn';
        $response = $this->gateway->get($this->lichessApiClient, $path, ['clocks' => 'false', 'comments' => 'true', 'variations' => 'true', 'orientation' => 'true'], $token);

        if (\in_array($response['status'], [401, 403, 404], true)) {
            throw new StudyUnavailableException(null === $token ? StudyUnavailableException::PRIVATE : StudyUnavailableException::NOT_FOUND);
        }
        if (200 !== $response['status'] || null === $response['text']) {
            throw new LichessUnavailableException(LichessUnavailableException::DOWN);
        }
        if (\strlen($response['text']) > $this->limits->maxImportBytes) {
            throw new StudyUnavailableException(StudyUnavailableException::TOO_LARGE);
        }

        return $response['text'];
    }
}
