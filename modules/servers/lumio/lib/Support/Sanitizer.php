<?php

declare(strict_types=1);

namespace Lumio\Whmcs\Support;

final class Sanitizer
{
    public static function text(string $value, int $maxLength = 240): string
    {
        $value = preg_replace(
            '/lumio_live_[A-Za-z0-9_-]{20,32}\.[A-Za-z0-9_-]{40,64}|Bearer\s+[A-Za-z0-9._~+\/=:-]{16,}/i',
            '[REDACTED]',
            $value,
        ) ?? '';
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        return mb_substr($value, 0, $maxLength);
    }

    public static function errorCode(string $value): string
    {
        $value = strtoupper(trim($value));
        return preg_match('/^[A-Z][A-Z0-9_]{1,63}$/D', $value) === 1 ? $value : 'INTERNAL_ERROR';
    }
}
