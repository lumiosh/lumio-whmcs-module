<?php

declare(strict_types=1);

namespace Lumio\Whmcs\Contract;

use Lumio\Whmcs\Http\HttpResponse;

interface TransportInterface
{
    /** @param list<string> $headers */
    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse;
}
