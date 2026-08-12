<?php

declare(strict_types=1);

namespace LumioWhmcsTests\Unit\Whmcs;

use Lumio\Whmcs\Contract\LoggerInterface;
use Lumio\Whmcs\Contract\RuntimeInterface;
use Lumio\Whmcs\Contract\StateRepositoryInterface;
use Lumio\Whmcs\CronRunner;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/modules/servers/lumio/lib/Autoload.php';

final class CronRunnerTest extends TestCase
{
    public function testReconcilesBoundedCreateAndLifecycleWork(): void
    {
        $runtime = new CronTestRuntime();
        $runtime->pendingCreates = [10, 11];
        $states = new CronTestStates();
        $states->pending = [
            ['service_id' => 20, 'pending_action' => 'suspend'],
            ['service_id' => 21, 'pending_action' => 'resume'],
            ['service_id' => 22, 'pending_action' => 'terminate'],
            ['service_id' => 23, 'pending_action' => 'renew'],
        ];
        $logger = new CronTestLogger();

        (new CronRunner($runtime, $states, $logger))->run();

        self::assertTrue($states->schemaEnsured);
        self::assertSame([
            ['ModuleCreate', 10],
            ['ModuleCreate', 11],
            ['ModuleSuspend', 20],
            ['ModuleUnsuspend', 21],
            ['ModuleTerminate', 22],
            ['ModuleRenew', 23],
        ], $runtime->commands);
    }

    public function testBusyCronLockDoesNotRunAnyRemoteWork(): void
    {
        $runtime = new CronTestRuntime();
        $runtime->runCronCallback = false;
        $runtime->pendingCreates = [10];
        $states = new CronTestStates();
        (new CronRunner($runtime, $states, new CronTestLogger()))->run();

        self::assertSame([], $runtime->commands);
    }

    public function testFailedCreateCommandIsDeferredForFiveMinutesInsteadOfTightLoop(): void
    {
        $runtime = new CronTestRuntime();
        $runtime->pendingCreates = [10];
        $runtime->commandResults['ModuleCreate:10'] = ['result' => 'error', 'message' => 'still provisioning'];
        $states = new CronTestStates();

        (new CronRunner($runtime, $states, new CronTestLogger()))->run();

        self::assertSame('WHMCS_RECONCILIATION_PENDING', $states->rows[10]['last_error_code']);
        self::assertNotNull($states->rows[10]['next_poll_at']);
        self::assertSame(1, $states->rows[10]['poll_attempts']);
        self::assertGreaterThanOrEqual(299, strtotime((string) $states->rows[10]['next_poll_at']) - time());
        self::assertLessThanOrEqual(300, strtotime((string) $states->rows[10]['next_poll_at']) - time());
    }

    public function testBlockedPurchaseKeepsItsActionableErrorInsteadOfBeingDeferred(): void
    {
        $runtime = new CronTestRuntime();
        $runtime->pendingCreates = [10];
        $runtime->commandResults['ModuleCreate:10'] = ['result' => 'error', 'message' => 'out of stock'];
        $states = new CronTestStates();
        $states->rows[10] = [
            'service_id' => 10,
            'delivery_state' => 'purchase_blocked',
            'poll_attempts' => 0,
            'next_poll_at' => null,
            'last_error_code' => 'OUT_OF_STOCK',
            'last_error_message' => 'OUT_OF_STOCK',
        ];

        (new CronRunner($runtime, $states, new CronTestLogger()))->run();

        self::assertSame('purchase_blocked', $states->rows[10]['delivery_state']);
        self::assertSame('OUT_OF_STOCK', $states->rows[10]['last_error_code']);
        self::assertNull($states->rows[10]['next_poll_at']);
        self::assertSame(0, $states->rows[10]['poll_attempts']);
    }

    public function testFailedRenewalReconciliationAlwaysWaitsFiveMinutes(): void
    {
        $runtime = new CronTestRuntime();
        $runtime->commandResults['ModuleRenew:30'] = ['result' => 'error', 'message' => 'network result unknown'];
        $states = new CronTestStates();
        $states->pending = [['service_id' => 30, 'pending_action' => 'renew']];
        $states->rows[30] = [
            'service_id' => 30,
            'pending_action' => 'renew',
            'poll_attempts' => 8,
            'next_poll_at' => null,
        ];

        (new CronRunner($runtime, $states, new CronTestLogger()))->run();

        self::assertSame([['ModuleRenew', 30]], $runtime->commands);
        self::assertSame(9, $states->rows[30]['poll_attempts']);
        self::assertGreaterThanOrEqual(299, strtotime((string) $states->rows[30]['next_poll_at']) - time());
        self::assertLessThanOrEqual(300, strtotime((string) $states->rows[30]['next_poll_at']) - time());
    }

    public function testFailedAcceptedSuspendRestoresWhmcsToActive(): void
    {
        $runtime = new CronTestRuntime();
        $states = new CronTestStates();
        $states->pending = [['service_id' => 20, 'pending_action' => 'suspend_rollback']];
        $states->rows[20] = [
            'service_id' => 20,
            'delivery_state' => 'suspended',
            'pending_action' => 'suspend_rollback',
            'pending_external_reference' => 'reference',
            'pending_operation_id' => 'operation',
            'pending_payload' => ['external_reference' => 'reference'],
            'poll_attempts' => 0,
            'next_poll_at' => null,
        ];

        (new CronRunner($runtime, $states, new CronTestLogger()))->run();

        self::assertSame([20], $runtime->restoredActiveServiceIds);
        self::assertSame('active', $states->rows[20]['delivery_state']);
        self::assertNull($states->rows[20]['pending_action']);
        self::assertNull($states->rows[20]['pending_operation_id']);
        self::assertSame([], $runtime->commands);
    }

    public function testFailedSuspendRollbackIsRetriedWithoutLosingItsPendingState(): void
    {
        $runtime = new CronTestRuntime();
        $runtime->restoreActiveError = new \RuntimeException('local API unavailable');
        $states = new CronTestStates();
        $states->pending = [['service_id' => 20, 'pending_action' => 'suspend_rollback']];
        $states->rows[20] = [
            'service_id' => 20,
            'pending_action' => 'suspend_rollback',
            'poll_attempts' => 0,
            'next_poll_at' => null,
        ];

        (new CronRunner($runtime, $states, new CronTestLogger()))->run();

        self::assertSame('suspend_rollback', $states->rows[20]['pending_action']);
        self::assertSame(1, $states->rows[20]['poll_attempts']);
        self::assertNotNull($states->rows[20]['next_poll_at']);
    }
}

final class CronTestRuntime implements RuntimeInterface
{
    /** @var list<int> */
    public array $pendingCreates = [];
    /** @var list<array{string, int}> */
    public array $commands = [];
    /** @var array<string, array<string, mixed>> */
    public array $commandResults = [];
    /** @var list<int> */
    public array $restoredActiveServiceIds = [];
    public ?\Throwable $restoreActiveError = null;
    public bool $runCronCallback = true;

    public function withServiceLock(int $serviceId, callable $callback): mixed { return $callback(); }
    public function withCronLock(callable $callback): mixed { return $this->runCronCallback ? $callback() : null; }
    public function latestPaidHostingInvoiceId(int $serviceId): ?int { return null; }
    public function serviceStatus(int $serviceId): ?string { return null; }
    public function restoreActiveStatusAfterFailedSuspend(int $serviceId): void
    {
        if ($this->restoreActiveError !== null) {
            throw $this->restoreActiveError;
        }
        $this->restoredActiveServiceIds[] = $serviceId;
    }
    public function assertProductCompatible(int $serviceId): void {}
    public function pendingCreateServiceIds(int $limit): array { return array_slice($this->pendingCreates, 0, $limit); }
    public function runModuleCommand(string $command, int $serviceId): array
    {
        $this->commands[] = [$command, $serviceId];
        return $this->commandResults[$command . ':' . $serviceId] ?? ['result' => 'success'];
    }
}

final class CronTestStates implements StateRepositoryInterface
{
    public bool $schemaEnsured = false;
    /** @var list<array{service_id: int, pending_action: string}> */
    public array $pending = [];
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    public function ensureSchema(): void { $this->schemaEnsured = true; }
    public function get(int $serviceId): array
    {
        return $this->rows[$serviceId] ?? [
            'service_id' => $serviceId,
            'poll_attempts' => 0,
            'next_poll_at' => null,
        ];
    }
    public function save(int $serviceId, array $changes): void { $this->rows[$serviceId] = $changes + $this->get($serviceId); }
    public function pendingLifecycle(int $limit): array { return array_slice($this->pending, 0, $limit); }
}

final class CronTestLogger implements LoggerInterface
{
    /** @var list<string> */
    public array $activities = [];
    public function apiCall(string $action, array $request, array $response): void {}
    public function activity(string $message): void { $this->activities[] = $message; }
}
