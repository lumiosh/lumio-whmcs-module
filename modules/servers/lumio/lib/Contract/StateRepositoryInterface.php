<?php

declare(strict_types=1);

namespace Lumio\Whmcs\Contract;

interface StateRepositoryInterface
{
    public function ensureSchema(): void;

    /** @return array<string, mixed> */
    public function get(int $serviceId): array;

    /** @param array<string, mixed> $changes */
    public function save(int $serviceId, array $changes): void;

    /** @return list<array<string, mixed>> */
    public function renewals(int $serviceId): array;

    /** @param array<string, mixed> $changes */
    public function saveRenewal(int $serviceId, int $invoiceId, array $changes): void;

    /** @return list<int> */
    public function pendingRenewalServiceIds(int $limit): array;

    /** @return list<array{service_id: int, pending_action: string}> */
    public function pendingLifecycle(int $limit): array;
}
