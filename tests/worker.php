<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
$app = new DailyPass\App();
$identity = $argv[2];
if ($argv[1] === 'selected') {
    $app->store->subscriber($identity); // A worker already selected an ACTIVE candidate.
    echo "SELECTED\n";
    fflush(STDOUT);
    fgets(STDIN); // Deterministic barrier controlled by the test.
}
if ($argv[1] === 'stop') {
    $app->subscriptions->stop($identity);
} else {
    echo $app->billing->charge($identity, gmdate('Y-m-d')) . PHP_EOL;
}
