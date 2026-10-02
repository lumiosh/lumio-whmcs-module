<?php

declare(strict_types=1);

namespace Lumio\Whmcs\Contract;

interface RuntimeInterface
{
    public function withServiceLock(int $serviceId, callable $callback): mixed;

    public function withCronLock(callable $callback): mixed;

    public function latestPaidHostingInvoiceId(int $serviceId): ?int;

    /** @return list<int> All paid hosting invoices, oldest first. */
    public function paidHostingInvoiceIds(int $serviceId): array;

    public function serviceStatus(int $serviceId): ?string;

    public function restoreActiveStatusAfterFailedSuspend(int $serviceId): void;

    public function assertProductCompatible(int $serviceId): void;

    /** @return list<int> */
    public function pendingCreateServiceIds(int $limit): array;

    /** @return array<string, mixed> */
    public function runModuleCommand(string $command, int $serviceId): array;
}
