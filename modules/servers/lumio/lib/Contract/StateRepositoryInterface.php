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

    /** @return list<array{service_id: int, pending_action: string}> */
    public function pendingLifecycle(int $limit): array;
}
