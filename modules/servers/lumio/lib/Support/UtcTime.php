<?php

declare(strict_types=1);
namespace Lumio\Whmcs\Support;

final class UtcTime
{
    public static function timestamp(mixed $value): int|false
    {
        if (! is_string($value) || trim($value) === '') return false;
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Exception) {
            return false;
        }
    }
}
