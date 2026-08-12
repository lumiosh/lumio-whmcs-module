<?php

declare(strict_types=1);

namespace Lumio\Whmcs;

use Lumio\Whmcs\Contract\RuntimeInterface;
use Lumio\Whmcs\Exception\ConfigurationException;
use RuntimeException;
use WHMCS\Database\Capsule;

final class WhmcsRuntime implements RuntimeInterface
{
    public function withServiceLock(int $serviceId, callable $callback): mixed
    {
        return $this->withNamedLock($this->lockName('s', (string) $serviceId), 5, $callback);
    }

    public function withCronLock(callable $callback): mixed
    {
        return $this->withNamedLock($this->lockName('cron', 'v1'), 0, $callback, false);
    }

    public function latestPaidHostingInvoiceId(int $serviceId): ?int
    {
        $value = Capsule::table('tblinvoiceitems as item')
            ->join('tblinvoices as invoice', 'invoice.id', '=', 'item.invoiceid')
            ->where('item.type', 'Hosting')
            ->where('item.relid', $serviceId)
            ->where('invoice.status', 'Paid')
            ->orderByDesc('invoice.id')
            ->value('invoice.id');
        return $value === null ? null : (int) $value;
    }

    public function serviceStatus(int $serviceId): ?string
    {
        $value = Capsule::table('tblhosting')->where('id', $serviceId)->value('domainstatus');
        return $value === null ? null : (string) $value;
    }

    public function restoreActiveStatusAfterFailedSuspend(int $serviceId): void
    {
        if ($serviceId < 1) {
            throw new \InvalidArgumentException('The WHMCS service ID is invalid');
        }
        if (! function_exists('localAPI')) {
            throw new RuntimeException('The WHMCS Local API is unavailable');
        }
        $result = localAPI('UpdateClientProduct', [
            'serviceid' => $serviceId,
            'status' => 'Active',
            'unset' => ['suspendreason'],
        ]);
        if (! is_array($result) || strtolower((string) ($result['result'] ?? '')) !== 'success') {
            $message = is_array($result) ? trim((string) ($result['message'] ?? '')) : '';
            throw new RuntimeException($message === ''
                ? 'WHMCS could not restore the service to Active after the Lumio suspension failed'
                : 'WHMCS could not restore the service to Active: ' . $message);
        }
    }

    public function assertProductCompatible(int $serviceId): void
    {
        $product = Capsule::table('tblhosting as service')
            ->join('tblproducts as product', 'product.id', '=', 'service.packageid')
            ->where('service.id', $serviceId)
            ->select(['product.servertype', 'product.proratabilling'])
            ->first();
        if ($product === null || (string) $product->servertype !== 'lumio') {
            throw new ConfigurationException('The WHMCS service is not assigned to the Lumio provisioning module');
        }
        if ((int) $product->proratabilling !== 0) {
            throw new ConfigurationException('Lumio products do not support WHMCS Prorata Billing; disable it before provisioning');
        }
    }

    public function pendingCreateServiceIds(int $limit): array
    {
        return Capsule::table('tblhosting as service')
            ->join('tblproducts as product', 'product.id', '=', 'service.packageid')
            ->join('mod_lumio_service_state as lumio', 'lumio.service_id', '=', 'service.id')
            ->where('product.servertype', 'lumio')
            ->where('service.domainstatus', 'Pending')
            ->where('service.server', '>', 0)
            ->whereNotNull('lumio.purchase_external_reference')
            ->whereNull('lumio.activation_reported_at')
            ->whereIn('lumio.delivery_state', [
                'purchasing',
                'paid_pending_service',
                'provisioning',
                'needs_attention',
                'active',
                'ready',
            ])
            ->where(static function ($query): void {
                $query->whereNull('lumio.next_poll_at')
                    ->orWhere('lumio.next_poll_at', '<=', gmdate('Y-m-d H:i:s'));
            })
            ->orderBy('service.id')
            ->limit(max(1, min($limit, 100)))
            ->pluck('service.id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    public function runModuleCommand(string $command, int $serviceId): array
    {
        $allowed = ['ModuleCreate', 'ModuleRenew', 'ModuleSuspend', 'ModuleUnsuspend', 'ModuleTerminate'];
        if (! in_array($command, $allowed, true)) {
            throw new \InvalidArgumentException('The WHMCS module command is not supported');
        }
        if (! function_exists('localAPI')) {
            throw new RuntimeException('The WHMCS Local API is unavailable');
        }
        $result = $command === 'ModuleRenew'
            ? localAPI('ModuleCustom', ['serviceid' => $serviceId, 'func_name' => 'ReconcileRenewal'])
            : localAPI($command, ['serviceid' => $serviceId]);
        return is_array($result) ? $result : ['result' => 'error', 'message' => 'The WHMCS Local API returned an invalid result'];
    }

    private function withNamedLock(
        string $name,
        int $timeout,
        callable $callback,
        bool $throwWhenBusy = true,
    ): mixed {
        $rows = Capsule::select('SELECT GET_LOCK(?, ?) AS acquired', [$name, $timeout]);
        if ((int) ($rows[0]->acquired ?? 0) !== 1) {
            if ($throwWhenBusy) {
                throw new RuntimeException('Another Lumio module operation is already running; try again later');
            }
            return null;
        }
        try {
            return $callback();
        } finally {
            Capsule::select('SELECT RELEASE_LOCK(?) AS released', [$name]);
        }
    }

    private function lockName(string $scope, string $subject): string
    {
        $rows = Capsule::select('SELECT DATABASE() AS database_name');
        $database = trim((string) ($rows[0]->database_name ?? ''));
        if ($database === '') {
            throw new RuntimeException('Unable to identify the current WHMCS database; the Lumio lock cannot be acquired safely');
        }
        return sprintf('lumio-%s-%s-%s', substr(hash('sha256', $database), 0, 12), $scope, sha1($subject));
    }
}
