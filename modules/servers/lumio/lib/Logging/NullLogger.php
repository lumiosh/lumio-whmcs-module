<?php

declare(strict_types=1);

namespace Lumio\Whmcs\Logging;

use Lumio\Whmcs\Contract\LoggerInterface;

final class NullLogger implements LoggerInterface
{
    public function apiCall(string $action, array $request, array $response): void {}

    public function activity(string $message): void {}
}
