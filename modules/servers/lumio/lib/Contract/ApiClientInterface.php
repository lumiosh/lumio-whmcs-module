<?php

declare(strict_types=1);

namespace Lumio\Whmcs\Contract;

interface ApiClientInterface
{
    /** @return array<string, mixed> */
    public function account(): array;

    /** @return array<string, mixed> */
    public function catalog(): array;

    /** @return array<string, mixed> */
    public function product(string $sku): array;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function purchase(array $payload, string $idempotencyKey): array;

    /** @return array<string, mixed> */
    public function serviceByReference(string $externalReference): array;

    /** @return array<string, mixed> */
    public function service(int $serviceId): array;

    /** @return array<string, mixed> */
    public function credentials(int $serviceId): array;

    /** @return array<string, mixed> */
    public function operation(string $operationId): array;

    /** @return array<string, mixed> */
    public function renewalQuote(int $serviceId): array;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function renew(int $serviceId, array $payload, string $idempotencyKey): array;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function lifecycle(int $serviceId, string $action, array $payload, string $idempotencyKey): array;

    public function lastRequestId(): ?string;
}
