<?php

declare(strict_types=1);

/*
 * Migrations of the puzzle catalogue's database (docs/DEPLOY_OVH.md, § 3), apart from the main ones:
 *
 *     bin/console doctrine:migrations:migrate --configuration=config/migrations/catalog.php
 *
 * The same option works for every doctrine:migrations:* command (status, diff, version...).
 */
return [
    'em' => 'catalog',
    'migrations_paths' => [
        // Not autoloaded, like the main DoctrineMigrations namespace.
        'DoctrineMigrationsCatalog' => dirname(__DIR__, 2).'/migrations_catalog',
    ],
    'transactional' => false,
    'check_database_platform' => true,
    'organize_migrations' => 'none',
];
