<?php

declare(strict_types=1);

namespace App\Evaluation\Verification;

use App\Repertoire\Lichess\LichessGateway;
use App\Repertoire\Lichess\LichessUnavailableException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Lichess endgame tablebase (tablebase.lichess.ovh, positions of 7 pieces at most, no token),
 * through {@see LichessGateway}: the exact result with perfect play.
 */
final readonly class TablebaseClient
{
    public const MAX_PIECES = 7;

    public function __construct(
        private HttpClientInterface $lichessTablebaseClient,
        private LichessGateway $gateway,
    ) {
    }

    /**
     * The result for the side to move: "win", "loss" or "draw" (a win or a loss spoiled by the
     * 50-move rule is a draw), null when the tablebase does not know it.
     *
     * @throws LichessUnavailableException
     */
    public function result(string $fen): ?string
    {
        $response = $this->gateway->get($this->lichessTablebaseClient, '/standard', ['fen' => $fen], null);
        if (200 !== $response['status'] || null === $response['body']) {
            return null;
        }

        return match ($response['body']['category'] ?? null) {
            'win' => 'win',
            'loss' => 'loss',
            'draw', 'cursed-win', 'blessed-loss' => 'draw',
            default => null,
        };
    }
}
