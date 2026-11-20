<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$app = new DailyPass\App();
echo json_encode($app->billing->run(), JSON_THROW_ON_ERROR) . PHP_EOL;
$app->sms->dispatch();
