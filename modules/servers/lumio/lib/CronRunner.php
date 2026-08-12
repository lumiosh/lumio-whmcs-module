<?php

declare(strict_types=1);

namespace Lumio\Whmcs;

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
            $this->runCommand('ModuleCreate', $serviceId);
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
        foreach ($this->states->pendingLifecycle(self::BATCH_SIZE) as $pending) {
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

    private function runCommand(string $command, int $serviceId): void
    {
        try {
            $result = $this->runtime->runModuleCommand($command, $serviceId);
            if (strtolower((string) ($result['result'] ?? '')) !== 'success') {
                $message = Sanitizer::text((string) ($result['message'] ?? 'The WHMCS module action is still pending'));
                if ($command === 'ModuleSuspend'
                    && ($this->states->get($serviceId)['pending_action'] ?? null) === 'suspend_rollback') {
                    $this->restoreFailedSuspend($serviceId);
                    return;
                }
                $this->deferUnlessScheduled($serviceId, $message);
            }
        } catch (\Throwable $exception) {
            $message = Sanitizer::text($exception->getMessage());
            $this->deferUnlessScheduled($serviceId, $message);
            $this->logger->activity(sprintf('Reconciliation failed for service #%d command %s: %s', $serviceId, $command, $message));
        }
    }

    private function restoreFailedSuspend(int $serviceId): void
    {
        try {
            $this->runtime->restoreActiveStatusAfterFailedSuspend($serviceId);
            $this->states->save($serviceId, [
                'delivery_state' => 'active',
                'pending_action' => null,
                'pending_external_reference' => null,
                'pending_operation_id' => null,
                'pending_payload' => null,
                'poll_attempts' => 0,
                'next_poll_at' => null,
            ]);
            $this->logger->activity(sprintf(
                'Lumio suspension failed for service #%d; WHMCS restored the local service status to Active',
                $serviceId,
            ));
        } catch (\Throwable $exception) {
            $state = $this->states->get($serviceId);
            $attempts = min(30, max(0, (int) ($state['poll_attempts'] ?? 0)) + 1);
            $this->states->save($serviceId, [
                'pending_action' => 'suspend_rollback',
                'poll_attempts' => $attempts,
                'next_poll_at' => gmdate('Y-m-d H:i:s', time() + self::RECONCILIATION_INTERVAL_SECONDS),
                'last_error_message' => Sanitizer::text(
                    'The Lumio suspension failed and WHMCS could not restore Active status: ' . $exception->getMessage(),
                    255,
                ),
            ]);
            $this->logger->activity(sprintf(
                'Failed to restore WHMCS service #%d to Active after Lumio suspension failure: %s',
                $serviceId,
                Sanitizer::text($exception->getMessage()),
            ));
        }
    }

    private function deferUnlessScheduled(int $serviceId, string $message): void
    {
        try {
            $state = $this->states->get($serviceId);
            if (($state['delivery_state'] ?? null) === 'purchase_blocked') {
                return;
            }
            $scheduledAt = strtotime((string) ($state['next_poll_at'] ?? ''));
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
