<?php

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

// Rolled-back test transactions (DAMA) never give auto-increment values back: puzzle_theme's
// SMALLINT ids would overflow after a dozen full runs (every test recreates the themes). The table
// is empty between tests, so the counter restarts at 1 (catalogue database: docs/DEPLOY_OVH.md, § 3).
// Unit tests still run without a database.
$kernel = new Kernel('test', false);
$kernel->boot();
try {
    $kernel->getContainer()->get('doctrine.dbal.catalog_connection')->executeStatement('ALTER TABLE puzzle_theme AUTO_INCREMENT = 1');
} catch (Doctrine\DBAL\Exception $e) {
    fwrite(STDERR, 'Could not reset puzzle_theme AUTO_INCREMENT: '.$e->getMessage().PHP_EOL);
} finally {
    $kernel->shutdown();
}
