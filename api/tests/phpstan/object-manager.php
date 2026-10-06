<?php

use Symfony\Component\Dotenv\Dotenv;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');

$kernel = new App\Kernel('dev', true);
$kernel->boot();

// The registry, not one manager: the puzzle catalogue has its own (docs/DEPLOY_OVH.md, § 3).
return $kernel->getContainer()->get('doctrine');
