<?php
declare(strict_types=1);
namespace DailyPass;

final class Config
{
    public static function secret(string $env, string $file): string
    {
        $value = getenv($env) ?: @file_get_contents('/run/daily-pass/' . $file);
        if (!is_string($value) || trim($value) === '') {
            throw new \RuntimeException('Missing configuration: ' . $env);
        }
        return trim($value);
    }

    public static function db(): \PDO
    {
        $db = new \PDO(getenv('DB_DSN') ?: 'mysql:host=db;dbname=daily_pass;charset=utf8mb4', getenv('DB_USER') ?: 'daily_pass', self::secret('DB_PASSWORD', 'db_password'), [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db->exec("SET time_zone = '+00:00'");
        return $db;
    }

    public static function fee(): string
    {
        return Validation::amount(getenv('DAILY_FEE') ?: '5.00');
    }
}
