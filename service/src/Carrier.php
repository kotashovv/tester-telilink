<?php
declare(strict_types=1);
namespace DailyPass;

interface Carrier
{
    /** Transport errors throw; callers must consider debit outcomes ambiguous. */
    public function request(string $path, array $payload): array;
}
