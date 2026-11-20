<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$db = DailyPass\Config::db();
if ((int) $db->query("SELECT GET_LOCK('daily-pass-migrations', 30)")->fetchColumn() !== 1) {
    throw new RuntimeException('Migration lock unavailable');
}
try {
    $db->exec('CREATE TABLE IF NOT EXISTS migrations (version VARCHAR(100) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
    foreach (glob(dirname(__DIR__) . '/migrations/*.sql') as $file) {
        $version = basename($file);
        $check = $db->prepare('SELECT 1 FROM migrations WHERE version=?');
        $check->execute([$version]);
        if (!$check->fetchColumn()) {
            $db->exec(file_get_contents($file));
            $db->prepare('INSERT INTO migrations(version) VALUES (?)')->execute([$version]);
        }
    }
} finally {
    $db->query("SELECT RELEASE_LOCK('daily-pass-migrations')");
}
