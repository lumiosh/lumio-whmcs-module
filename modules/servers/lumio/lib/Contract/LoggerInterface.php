<?php

declare(strict_types=1);

namespace Lumio\Whmcs\Contract;

interface LoggerInterface
{
    /** @param array<string, int|string|bool|null> $request @param array<string, int|string|bool|null> $response */
    public function apiCall(string $action, array $request, array $response): void;

    public function activity(string $message): void;
}
