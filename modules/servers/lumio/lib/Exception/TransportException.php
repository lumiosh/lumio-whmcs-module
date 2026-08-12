<?php

declare(strict_types=1);

namespace Lumio\Whmcs\Exception;

final class TransportException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $requestId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
