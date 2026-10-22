<?php

declare(strict_types=1);

/*
 * Migrations of the endgame tablebase's database (docs/ENDGAMES.md, docs/DEPLOY_OVH.md, § 3),
 * apart from the main ones:
 *
 *     bin/console doctrine:migrations:migrate --configuration=config/migrations/endgame.php
 *
 * The same option works for every doctrine:migrations:* command (status, diff, version...).
 */
return [
    'em' => 'endgame',
    'migrations_paths' => [
        // Not autoloaded, like the main DoctrineMigrations namespace.
        'DoctrineMigrationsEndgame' => dirname(__DIR__, 2).'/migrations_endgame',
    ],
    'transactional' => false,
    'check_database_platform' => true,
    'organize_migrations' => 'none',
];
