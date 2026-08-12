<?php

declare(strict_types=1);

namespace Lumio\Whmcs;

use Lumio\Whmcs\Contract\ApiClientInterface;
use Lumio\Whmcs\Contract\LoggerInterface;
use Lumio\Whmcs\Contract\TransportInterface;
use Lumio\Whmcs\Exception\ApiException;
use Lumio\Whmcs\Exception\TransportException;
use Lumio\Whmcs\Support\Sanitizer;

final class ApiClient implements ApiClientInterface
{
    private ?string $lastRequestId = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly TransportInterface $transport,
        private readonly LoggerInterface $logger,
    ) {}

    public function account(): array
    {
        return $this->request('GET', '/account');
    }

    public function catalog(): array
    {
        return $this->request('GET', '/catalog/products');
    }

    public function product(string $sku): array
    {
        return $this->request('GET', '/catalog/products/' . rawurlencode($sku));
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function purchase(array $payload, string $idempotencyKey): array
    {
        return $this->request('POST', '/purchases', $payload, $idempotencyKey);
    }

    public function serviceByReference(string $externalReference): array
    {
        return $this->request('GET', '/services/by-reference/' . rawurlencode($externalReference));
    }

    public function service(int $serviceId): array
    {
        if ($serviceId < 1) {
            throw new \InvalidArgumentException('The Lumio Service ID is invalid');
        }
        return $this->request('GET', sprintf('/services/%d', $serviceId));
    }

    public function credentials(int $serviceId): array
    {
        return $this->request('GET', sprintf('/services/%d/credentials', $serviceId));
    }

    public function operation(string $operationId): array
    {
        return $this->request('GET', '/operations/' . rawurlencode($operationId));
    }

    public function renewalQuote(int $serviceId): array
    {
        return $this->request('GET', sprintf('/services/%d/renewal-quote', $serviceId));
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function renew(int $serviceId, array $payload, string $idempotencyKey): array
    {
        return $this->request('POST', sprintf('/services/%d/renew', $serviceId), $payload, $idempotencyKey);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function lifecycle(int $serviceId, string $action, array $payload, string $idempotencyKey): array
    {
        if (! in_array($action, ['suspend', 'resume', 'terminate'], true)) {
            throw new \InvalidArgumentException('The Lumio lifecycle action is not supported');
        }
        return $this->request(
            'POST',
            sprintf('/services/%d/%s', $serviceId, $action),
            $payload,
            $idempotencyKey,
        );
    }

    public function lastRequestId(): ?string
    {
        return $this->lastRequestId;
    }

    /**
     * @param null|array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        string $path,
        ?array $body = null,
        ?string $idempotencyKey = null,
    ): array {
        $requestId = 'whmcs-' . bin2hex(random_bytes(16));
        $this->lastRequestId = $requestId;
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->apiKey,
            'X-Request-Id: ' . $requestId,
            'User-Agent: Lumio-WHMCS/' . Version::NUMBER,
        ];
        $encoded = null;
        if ($body !== null) {
            try {
                $encoded = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } catch (\JsonException $exception) {
                throw new \InvalidArgumentException('The Lumio request payload could not be encoded', 0, $exception);
            }
            $headers[] = 'Content-Type: application/json';
        }
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        $requestLog = [
            'method' => $method,
            'path' => $path,
            'request_id' => $requestId,
            'has_body' => $body !== null,
            'has_idempotency_key' => $idempotencyKey !== null,
        ];

        try {
            $response = $this->transport->send(
                $method,
                rtrim($this->baseUrl, '/') . $path,
                $headers,
                $encoded,
            );
        } catch (TransportException $exception) {
            $this->logger->apiCall($path, $requestLog, [
                'status' => 0,
                'error' => 'TRANSPORT_ERROR',
                'request_id' => $requestId,
            ]);
            throw new TransportException($exception->getMessage(), $requestId, $exception);
        }

        $contentType = strtolower($response->headers['content-type'] ?? '');
        if ($contentType !== '' && ! str_contains($contentType, 'application/json')) {
            $this->logger->apiCall($path, $requestLog, [
                'status' => $response->status,
                'error' => 'INVALID_RESPONSE',
                'request_id' => $requestId,
            ]);
            throw new TransportException('The Lumio API returned a non-JSON response', $requestId);
        }

        try {
            $envelope = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->logger->apiCall($path, $requestLog, [
                'status' => $response->status,
                'error' => 'INVALID_RESPONSE',
                'request_id' => $requestId,
            ]);
            throw new TransportException('The Lumio API returned invalid JSON', $requestId, $exception);
        }
        if (! is_array($envelope)) {
            throw new TransportException('The Lumio API response structure is invalid', $requestId);
        }

        $code = (int) ($envelope['code'] ?? $response->status);
        if ($response->status < 200 || $response->status >= 300 || $code !== 200) {
            $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
            $errorCode = Sanitizer::errorCode((string) ($data['error'] ?? 'INTERNAL_ERROR'));
            $responseRequestId = trim((string) ($data['request_id'] ?? ''));
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D', $responseRequestId) !== 1) {
                $responseRequestId = $requestId;
            }
            $retryAfter = isset($response->headers['retry-after'])
                ? max(0, (int) $response->headers['retry-after'])
                : null;
            $this->lastRequestId = $responseRequestId;
            $this->logger->apiCall($path, $requestLog, [
                'status' => $response->status,
                'error' => $errorCode,
                'request_id' => $responseRequestId,
                'retry_after' => $retryAfter,
            ]);
            throw new ApiException(
                $response->status,
                $errorCode,
                $responseRequestId,
                $retryAfter,
                Sanitizer::text((string) ($envelope['message'] ?? 'Lumio API request failed')),
            );
        }

        $data = $envelope['data'] ?? null;
        if (! is_array($data)) {
            throw new TransportException('The successful Lumio API response is missing data', $requestId);
        }
        $this->logger->apiCall($path, $requestLog, [
            'status' => $response->status,
            'error' => null,
            'request_id' => $requestId,
        ]);
        return $data;
    }
}
