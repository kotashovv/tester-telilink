<?php
declare(strict_types=1);
namespace DailyPass;

final class Validation
{
    public static function identity(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9:_.@+*\-]{0,159}\z/D', $value)) {
            throw new \InvalidArgumentException('subscriberId must be a stable ASCII identifier (1–160 characters)');
        }
        if (str_starts_with($value, 'tel:')) {
            self::phone($value);
        }
        return $value;
    }

    public static function phone(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\Atel:[0-9]{5,15}\z/D', $value)) {
            throw new \InvalidArgumentException('MSISDN must be tel: followed by 5–15 digits');
        }
        return $value;
    }

    public static function amount(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\A(?:0|[1-9][0-9]{0,9})\.[0-9]{2}\z/D', $value) || $value === '0.00') {
            throw new \InvalidArgumentException('Amount must be a positive decimal string with exactly two fractional digits');
        }
        return $value;
    }

    public static function timestamp(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/', $value)) {
            throw new \InvalidArgumentException('timeStamp must be an ISO-8601 timestamp with timezone');
        }
        try {
            $date = new \DateTimeImmutable($value);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors && ($errors['warning_count'] || $errors['error_count'])) {
                throw new \Exception();
            }
        } catch (\Exception) {
            throw new \InvalidArgumentException('Invalid timeStamp');
        }
        if ($date > new \DateTimeImmutable('+5 minutes')) {
            throw new \InvalidArgumentException('timeStamp is too far in the future');
        }
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
