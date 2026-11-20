<?php
declare(strict_types=1);
use DailyPass\{RetryPolicy, Validation};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PolicyTest extends TestCase
{
    public static function classifications(): array
    {
        return [
            ['S1000', 1, 'SUCCEEDED'], ['E1326', 1, 'RETRY_SCHEDULED'], ['E1326', 3, 'EXHAUSTED'],
            ['E1318', 2, 'RETRY_SCHEDULED'], ['E1318', 3, 'EXHAUSTED'], ['E1308', 1, 'FAILED_PERMANENT'],
            ['E1337', 1, 'UNKNOWN'], ['TRANSPORT_UNKNOWN', 1, 'UNKNOWN'], ['UNRECOGNIZED', 1, 'UNKNOWN'],
        ];
    }

    #[DataProvider('classifications')]
    public function testClassification(string $code, int $attempt, string $state): void
    {
        self::assertSame($state, RetryPolicy::state($code, $attempt));
    }

    public function testStableNamespacedKeyAndBackoff(): void
    {
        $key = RetryPolicy::key('app', 'masked:abc', '2026-01-01');
        self::assertSame($key, RetryPolicy::key('app', 'masked:abc', '2026-01-01'));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $key);
        self::assertNotSame($key, RetryPolicy::key('app', 'masked:abc', '2026-01-02'));
        self::assertNotSame($key, RetryPolicy::key('app2', 'masked:abc', '2026-01-01'));
        self::assertNotSame($key, RetryPolicy::key('app', 'masked:def', '2026-01-01'));
        self::assertSame(60, RetryPolicy::delay(1));
        self::assertSame(120, RetryPolicy::delay(2));
    }

    public static function invalidAmounts(): array
    {
        return [['5'], ['5.0'], ['5.000'], [5.00], ['-1.00'], ['0.00'], ['1e2'], ['01.00'], ['10000000000.00']];
    }

    #[DataProvider('invalidAmounts')]
    public function testInvalidAmounts(mixed $amount): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validation::amount($amount);
    }

    public function testOpaqueIdentityAndPhone(): void
    {
        self::assertSame('masked:ABC***12', Validation::identity('masked:ABC***12'));
        self::assertSame('tel:1000000001', Validation::phone('tel:1000000001'));
        self::assertSame('5.00', Validation::amount('5.00'));
        $this->expectException(InvalidArgumentException::class);
        Validation::identity('tel:garbage');
    }

    public function testTimestampRejectsImpossibleDate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validation::timestamp('2026-02-30T00:00:00Z');
    }
}
