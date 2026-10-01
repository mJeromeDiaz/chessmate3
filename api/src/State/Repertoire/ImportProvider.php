<?php

declare(strict_types=1);

namespace App\State\Repertoire;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Repertoire\Import;
use App\Enum\Repertoire\Color;
use App\Repertoire\Exception\RepertoireNotFoundException;
use App\Repertoire\Import\ImportService;
use App\Repertoire\Limits;
use App\Security\AuthenticatedUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * GET /repertoires/imports/{id}?repertoireId=|color=|choices[fen]=uci: the import, with its preview
 * against that destination and with these choices, once analysed (default: a new repertoire of
 * the file's color, else White). 404 for an unknown destination.
 *
 * @implements ProviderInterface<Import>
 */
final class ImportProvider implements ProviderInterface
{
    public function __construct(
        private readonly ImportService $imports,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly Limits $limits,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?Import
    {
        $id = RepertoireIds::fromUri($uriVariables, 'id');
        $import = null === $id ? null : $this->imports->get($this->authenticatedUser->get(), $id);
        if (null === $import) {
            return null;
        }
        $filters = \is_array($context['filters'] ?? null) ? $context['filters'] : [];
        $repertoireId = \is_string($filters['repertoireId'] ?? null) && Uuid::isValid($filters['repertoireId']) ? Uuid::fromString($filters['repertoireId']) : null;
        $view = Import::from($import);
        $color = Color::tryFrom(\is_string($filters['color'] ?? null) ? $filters['color'] : '') ?? Color::tryFrom((string) $view->suggestedColor) ?? Color::White;
        $choices = [];
        foreach (\is_array($filters['choices'] ?? null) ? $filters['choices'] : [] as $fen => $uci) {
            if (\is_string($uci) && 1 === preg_match('/^[a-h][1-8][a-h][1-8][qrbn]?$/', $uci)) {
                $choices[(string) $fen] = $uci;
            }
        }

        try {
            $plan = $this->imports->preview($import, $repertoireId, $color, $choices);
        } catch (RepertoireNotFoundException) {
            throw new NotFoundHttpException('Repertoire not found.');
        }

        return null === $plan ? $view : Import::from($import, $plan, $repertoireId?->toRfc4122(), null === $repertoireId ? $color->value : null, $this->limits->maxPositions);
    }
}
