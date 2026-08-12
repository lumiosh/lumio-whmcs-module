<?php

declare(strict_types=1);

namespace Lumio\Whmcs\Http;

use Lumio\Whmcs\Contract\TransportInterface;
use Lumio\Whmcs\Exception\TransportException;

final class CurlTransport implements TransportInterface
{
    private const MAX_RESPONSE_BYTES = 1_048_576;

    public function __construct(
        private readonly int $connectTimeoutSeconds = 10,
        private readonly int $timeoutSeconds = 30,
    ) {}

    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        if (! extension_loaded('curl')) {
            throw new TransportException('The PHP cURL extension is not enabled on this WHMCS server');
        }
        if (! str_starts_with($url, 'https://')) {
            throw new TransportException('The Lumio API requires HTTPS');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new TransportException('Unable to initialize the Lumio API connection');
        }

        $responseHeaders = [];
        $responseBody = '';
        $tooLarge = false;
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$responseBody, &$tooLarge): int {
                if (strlen($responseBody) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        try {
            $result = curl_exec($handle);
            if ($result === false) {
                if ($tooLarge) {
                    throw new TransportException('The Lumio API response exceeds the safe size limit');
                }
                throw new TransportException('Lumio API network request failed: ' . curl_error($handle));
            }
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($handle);
        }

        return new HttpResponse($status, $responseHeaders, $responseBody);
    }
}
