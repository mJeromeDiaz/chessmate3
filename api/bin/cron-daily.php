<?php

/*
 * Daily tasks of the OVH shared host (docs/DEPLOY_OVH.md): OVH's scheduled tasks run a PHP script,
 * not a shell command, so this one runs the console commands a crontab would run daily:
 *   cache:pool:prune   expired Lichess explorer and cloud-eval answers
 *   app:account:purge  accounts whose deletion is due (docs/AUTH.md)
 * Scheduled in the OVH manager: script `<dir>/api/bin/cron-daily.php`, PHP 8.3, daily.
 */

declare(strict_types=1);

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

$kernel = new Kernel((string) $_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$application = new Application($kernel);
$application->setAutoExit(false);
$output = new ConsoleOutput();

$status = 0;
foreach (['cache:pool:prune', 'app:account:purge'] as $command) {
    $output->writeln(sprintf('[%s] %s', date(DATE_ATOM), $command));
    $status = max($status, $application->run(new ArrayInput(['command' => $command, '--no-interaction' => true]), $output));
}

exit($status);
