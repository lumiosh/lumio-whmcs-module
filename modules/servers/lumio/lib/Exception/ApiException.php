<?php

declare(strict_types=1);

namespace Lumio\Whmcs\Exception;

final class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $errorCode,
        public readonly ?string $requestId,
        public readonly ?int $retryAfter,
        string $message,
    ) {
        parent::__construct($message);
    }
}
