<?php

declare(strict_types=1);

namespace App\ApiResource\Repertoire;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Entity\Repertoire\Repertoire as RepertoireEntity;
use App\State\Repertoire\CreateRepertoireProcessor;
use App\State\Repertoire\DeleteRepertoireProcessor;
use App\State\Repertoire\RenameRepertoireProcessor;
use App\State\Repertoire\RepertoireProvider;

/**
 * An opening repertoire of the current user (docs/REPERTOIRE.md). Another user's answers 404.
 */
#[ApiResource(
    shortName: 'Repertoire',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new GetCollection(uriTemplate: '/repertoires', paginationEnabled: false, provider: RepertoireProvider::class),
        new Get(uriTemplate: '/repertoires/{id}', requirements: ['id' => self::UUID_PATTERN], provider: RepertoireProvider::class),
        new Post(uriTemplate: '/repertoires', input: CreateRepertoireInput::class, processor: CreateRepertoireProcessor::class),
        new Post(uriTemplate: '/repertoires/{id}/rename', requirements: ['id' => self::UUID_PATTERN], status: 200, input: RenameRepertoireInput::class, read: false, name: 'repertoire_rename', processor: RenameRepertoireProcessor::class),
        new Delete(uriTemplate: '/repertoires/{id}', requirements: ['id' => self::UUID_PATTERN], read: false, processor: DeleteRepertoireProcessor::class),
    ],
)]
final class Repertoire
{
    public const UUID_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    #[ApiProperty(identifier: true)]
    public string $id;
    public string $name;
    /** white or black */
    public string $color;
    public int $positionCount;
    /** Segments with at least one user's move (the units a test presents). */
    public int $segmentCount;
    public int $version;
    public \DateTimeImmutable $createdAt;
    public \DateTimeImmutable $updatedAt;

    public static function from(RepertoireEntity $repertoire, int $segmentCount): self
    {
        $view = new self();
        $view->id = $repertoire->getId()->toRfc4122();
        $view->name = $repertoire->getName();
        $view->color = $repertoire->getColor()->value;
        $view->positionCount = $repertoire->getPositionCount();
        $view->segmentCount = $segmentCount;
        $view->version = $repertoire->getVersion();
        $view->createdAt = $repertoire->getCreatedAt();
        $view->updatedAt = $repertoire->getUpdatedAt();

        return $view;
    }
}
