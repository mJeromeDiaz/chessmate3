<?php

/*
 * Daily tasks, one PHP script (OVH's shared host only ran scripts, docs/DEPLOY_OVH.md), which runs
 * the console commands in turn:
 *   cache:pool:prune   expired Lichess explorer and cloud-eval answers
 *   app:account:purge  accounts whose deletion is due (docs/AUTH.md)
 *   app:repertoire:refresh-opening-stats  the explorer's first moves, rows older than 30 days (docs/REPERTOIRE.md)
 *   app:endgame:prune-tablebase  tablebase answers unread for 90 days (docs/ENDGAMES.md)
 * Scheduled on the VPS by /etc/cron.d/dontstayrooky (deploy/vps/cron/dontstayrooky, docs/DEPLOY_VPS.md).
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
foreach (['cache:pool:prune', 'app:account:purge', 'app:repertoire:refresh-opening-stats', 'app:endgame:prune-tablebase'] as $command) {
    $output->writeln(sprintf('[%s] %s', date(DATE_ATOM), $command));
    $status = max($status, $application->run(new ArrayInput(['command' => $command, '--no-interaction' => true]), $output));
}

exit($status);
