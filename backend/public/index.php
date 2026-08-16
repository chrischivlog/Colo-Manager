<?php

declare(strict_types=1);

use ColoManager\Application;
require dirname(__DIR__) . '/vendor/autoload.php';

$application = new Application();
$application->run();
