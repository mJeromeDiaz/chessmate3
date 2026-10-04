<?php

declare(strict_types=1);

namespace App\ApiResource\Training;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\NotExposed;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\ApiResource\Woodpecker\Set;
use App\State\Training\SessionNextProcessor;

/**
 * The current step of a session, started: the session and the run to play.
 */
#[ApiResource(
    shortName: 'TrainingSessionLaunch',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Post(
            uriTemplate: '/training/sessions/{id}/next',
            requirements: ['id' => Set::UUID_PATTERN],
            status: 200,
            openapi: new Operation(summary: 'Starts the current step (or hands back its run in progress). 409 when it cannot start: the session then tells why.'),
            input: false,
            read: false,
            processor: SessionNextProcessor::class,
            name: 'training_session_next',
        ),
        // Item IRI only (answers 404), kept under the domain prefix.
        new NotExposed(uriTemplate: '/training/sessions/{id}/launch', requirements: ['id' => Set::UUID_PATTERN]),
    ],
)]
final class SessionLaunch
{
    /** The session's id. */
    #[ApiProperty(identifier: true)]
    public string $id;
    #[ApiProperty(readableLink: true, genId: false)]
    public Session $session;
    #[ApiProperty(readableLink: true, genId: false)]
    public Run $run;
}
