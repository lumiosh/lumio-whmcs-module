<?php

declare(strict_types=1);

namespace Lumio\Whmcs\Logging;

use Lumio\Whmcs\Contract\LoggerInterface;
use Lumio\Whmcs\Support\Sanitizer;

final class WhmcsLogger implements LoggerInterface
{
    public function __construct(private readonly string $apiKey = '') {}

    public function apiCall(string $action, array $request, array $response): void
    {
        if (! function_exists('logModuleCall')) {
            return;
        }
        logModuleCall(
            'lumio',
            Sanitizer::text($action, 80),
            $this->safeMetadataJson($request),
            $this->safeMetadataJson($response),
            [],
            $this->apiKey === '' ? [] : [$this->apiKey],
        );
    }

    public function activity(string $message): void
    {
        if (function_exists('logActivity')) {
            logActivity('Lumio: ' . Sanitizer::text($message));
        }
    }

    /**
     * @param array<string, int|string|bool|null> $metadata
     */
    private function safeMetadataJson(array $metadata): string
    {
        $safe = [];
        foreach ($metadata as $key => $value) {
            if (preg_match('/authorization|api.?key|password|secret|credential|token/i', (string) $key) === 1) {
                continue;
            }
            $safe[(string) $key] = is_string($value) ? Sanitizer::text($value) : $value;
        }
        return json_encode(
            $safe,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
