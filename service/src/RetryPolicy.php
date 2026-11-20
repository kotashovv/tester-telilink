<?php
declare(strict_types=1);
namespace DailyPass;

final class RetryPolicy
{
    public const MAX_ATTEMPTS = 3;

    public static function state(string $code, int $attempt): string
    {
        return match ($code) {
            'S1000' => 'SUCCEEDED',
            'E1308' => 'FAILED_PERMANENT',
            'E1326', 'E1318' => $attempt < self::MAX_ATTEMPTS ? 'RETRY_SCHEDULED' : 'EXHAUSTED',
            default => 'UNKNOWN', // E1337 is NOT proof of a successful debit.
        };
    }

    public static function delay(int $attempt): int
    {
        return 60 * (2 ** ($attempt - 1));
    }

    public static function key(string $application, string $identity, string $day): string
    {
        return hash('sha256', json_encode(['daily-pass-v1', $application, $identity, $day], JSON_THROW_ON_ERROR));
    }
}
