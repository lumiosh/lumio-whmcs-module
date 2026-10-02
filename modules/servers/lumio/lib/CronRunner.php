<?php

declare(strict_types=1);

namespace Lumio\Whmcs;

use Lumio\Whmcs\Support\UtcTime;

use Lumio\Whmcs\Contract\LoggerInterface;
use Lumio\Whmcs\Contract\RuntimeInterface;
use Lumio\Whmcs\Contract\StateRepositoryInterface;
use Lumio\Whmcs\Support\Sanitizer;

final class CronRunner
{
    private const BATCH_SIZE = 50;
    private const RECONCILIATION_INTERVAL_SECONDS = 300;

    public function __construct(
        private readonly RuntimeInterface $runtime,
        private readonly StateRepositoryInterface $states,
        private readonly LoggerInterface $logger,
    ) {}

    public function run(): void
    {
        try {
            $this->states->ensureSchema();
            $this->runtime->withCronLock(function (): void {
                $this->reconcileCreates();
                $this->reconcileLifecycle();
            });
        } catch (\Throwable $exception) {
            $this->logger->activity('Cron reconciliation failed: ' . Sanitizer::text($exception->getMessage()));
        }
    }

    private function reconcileCreates(): void
    {
        foreach ($this->runtime->pendingCreateServiceIds(self::BATCH_SIZE) as $serviceId) {
            if (! $this->expireWork($serviceId, 'create')) $this->runCommand('ModuleCreate', $serviceId);
        }
    }

    private function reconcileLifecycle(): void
    {
        $commands = [
            'renew' => 'ModuleRenew',
            'suspend' => 'ModuleSuspend',
            'resume' => 'ModuleUnsuspend',
            'terminate' => 'ModuleTerminate',
        ];
        $pendingRows = $this->states->pendingLifecycle(self::BATCH_SIZE);
        $legacyRenewals = array_column(array_filter($pendingRows, static fn ($row) => $row['pending_action'] === 'renew'), 'service_id');
        foreach ($this->states->pendingRenewalServiceIds(self::BATCH_SIZE) as $serviceId) {
            if (! in_array($serviceId, $legacyRenewals, true)) {
                $pendingRows[] = ['service_id' => $serviceId, 'pending_action' => 'renew'];
            }
        }
        foreach ($pendingRows as $pending) {
            if ($this->expireWork($pending['service_id'], $pending['pending_action'])) continue;
            if ($pending['pending_action'] === 'suspend_rollback') {
                $this->restoreFailedSuspend($pending['service_id']);
                continue;
            }
            $command = $commands[$pending['pending_action']] ?? null;
            if ($command === null) {
                continue;
            }
            $this->runCommand($command, $pending['service_id']);
        }
    }

    /** Bound retries even when WHMCS Local API fails before entering the workflow. */
    private function expireWork(int $serviceId, string $action): bool
    {
        return $this->runtime->withServiceLock($serviceId, function () use ($serviceId, $action): bool {
            $state = $this->states->get($serviceId);
            if ($action === 'create' && ! empty($state['activation_reported_at'])) return true;
            if ($action === 'renew') {
                foreach ($this->states->renewals($serviceId) as $row) {
                    if ($row['status'] === 'completed') continue;
                    $started = UtcTime::timestamp($row['started_at'] ?? null);
                    if ($started === false) {
                        $this->states->saveRenewal($serviceId, (int) $row['invoice_id'], ['started_at' => gmdate('Y-m-d H:i:s')]);
                        return false;
                    }
                    if ($started + RenewalProcessor::RETRY_WINDOW_SECONDS > time()) return false;
                    $this->states->saveRenewal($serviceId, (int) $row['invoice_id'], ['status' => 'needs_attention', 'next_poll_at' => null, 'last_error_code' => 'RENEWAL_RETRY_EXPIRED']);
                    $this->states->save($serviceId, ['last_error_code' => 'RENEWAL_RETRY_EXPIRED', 'last_error_message' => 'Use Retry Paid Renewals to reconcile the saved invoice request']);
                    return true;
                }
                if (($state['pending_action'] ?? null) !== 'renew') return false;
                // Legacy records are migrated when ModuleCustom becomes available.
            }
            $field = $action === 'create' ? 'purchase_started_at' : 'pending_started_at';
            if ($action !== 'create' && ($state['pending_action'] ?? null) !== $action) return true;
            $started = UtcTime::timestamp($state[$field] ?? null);
            if ($started === false) {
                $this->states->save($serviceId, [$field => gmdate('Y-m-d H:i:s')]);
                return false;
            }
            if ($started + ModuleWorkflow::PENDING_TIMEOUT_SECONDS > time()) return false;
            if ($action === 'create') {
                if (($state['last_error_code'] ?? null) !== 'PROVISIONING_RETRY_EXPIRED') {
                    $this->logger->activity(sprintf('Service #%d: provisioning timed out (PROVISIONING_RETRY_EXPIRED). Manually retry Create in WHMCS; the saved purchase request will be reused.', $serviceId));
                }
                $this->states->save($serviceId, ['delivery_state' => 'purchase_blocked', 'next_poll_at' => null,
                    'last_error_code' => 'PROVISIONING_RETRY_EXPIRED', 'last_error_message' => 'Retry the saved purchase explicitly']);
            } elseif ($action === 'renew') {
                // Preserve legacy invoice details for the next explicit migration/retry.
                $this->states->save($serviceId, ['pending_action' => 'renew_review', 'next_poll_at' => null,
                    'last_error_code' => 'RENEWAL_RETRY_EXPIRED', 'last_error_message' => 'Use Retry Paid Renewals to migrate and retry this invoice']);
            } else {
                $snapshot = array_intersect_key($state, array_flip(['pending_action', 'pending_payload', 'pending_external_reference', 'pending_operation_id', 'pending_started_at']));
                $this->states->save($serviceId, ['pending_action' => null, 'pending_started_at' => null, 'next_poll_at' => null,
                    'pending_payload' => null, 'pending_external_reference' => null, 'pending_operation_id' => null,
                    'interrupted_operation' => $snapshot + ['reason' => 'OPERATION_TIMED_OUT', 'resumable' => $action !== 'suspend_rollback'],
                    'last_error_code' => 'OPERATION_TIMED_OUT', 'last_error_message' => 'Automatic processing stopped; retry explicitly or choose another action']);
            }
            return true;
        });
    }

    private function runCommand(string $command, int $serviceId): void
    {
        try {
            $result = $this->runtime->runModuleCommand($command, $serviceId);
            if ($command === 'ModuleRenew') return; // The per-invoice ledger owns renewal retries.
            if (strtolower((string) ($result['result'] ?? '')) !== 'success') {
                $message = Sanitizer::text((string) ($result['message'] ?? 'The WHMCS module action is still pending'));
                if ($command === 'ModuleSuspend'
                    && ($this->states->get($serviceId)['pending_action'] ?? null) === 'suspend_rollback') {
                    $this->restoreFailedSuspend($serviceId);
                    return;
                }
                $this->deferUnlessScheduled($serviceId, $message, $command === 'ModuleCreate');
            }
        } catch (\Throwable $exception) {
            $message = Sanitizer::text($exception->getMessage());
            if ($command !== 'ModuleRenew') $this->deferUnlessScheduled($serviceId, $message, $command === 'ModuleCreate');
            $this->logger->activity(sprintf('Reconciliation failed for service #%d command %s: %s', $serviceId, $command, $message));
        }
    }

    private function restoreFailedSuspend(int $serviceId): void
    {
        // The workflow owns the remote hold release and its service lock. Never
        // restore WHMCS Active directly from cron before that release is confirmed.
        $this->runCommand('ModuleRollbackSuspend', $serviceId);
    }

    private function deferUnlessScheduled(int $serviceId, string $message, bool $create = false): void
    {
        try {
            $state = $this->states->get($serviceId);
            if (($state['delivery_state'] ?? null) === 'purchase_blocked'
                || (! $create && ($state['pending_action'] ?? null) === null)) {
                return;
            }
            $scheduledAt = UtcTime::timestamp((string) ($state['next_poll_at'] ?? ''));
            if ($scheduledAt !== false && $scheduledAt > time()) {
                return;
            }
            $attempts = min(30, max(0, (int) ($state['poll_attempts'] ?? 0)) + 1);
            $this->states->save($serviceId, [
                'poll_attempts' => $attempts,
                'next_poll_at' => gmdate('Y-m-d H:i:s', time() + self::RECONCILIATION_INTERVAL_SECONDS),
                'last_error_code' => 'WHMCS_RECONCILIATION_PENDING',
                'last_error_message' => Sanitizer::text($message, 255),
            ]);
        } catch (\Throwable) {
        }
    }
}
