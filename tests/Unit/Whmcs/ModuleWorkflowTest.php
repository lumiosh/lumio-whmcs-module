<?php

declare(strict_types=1);

namespace LumioWhmcsTests\Unit\Whmcs;

use Lumio\Whmcs\Configuration;
use Lumio\Whmcs\Contract\ApiClientInterface;
use Lumio\Whmcs\Contract\LoggerInterface;
use Lumio\Whmcs\Contract\RuntimeInterface;
use Lumio\Whmcs\Contract\ServicePropertiesInterface;
use Lumio\Whmcs\Contract\StateRepositoryInterface;
use Lumio\Whmcs\Exception\ApiException;
use Lumio\Whmcs\Exception\ConfigurationException;
use Lumio\Whmcs\Exception\TransportException;
use Lumio\Whmcs\ModuleWorkflow;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/modules/servers/lumio/lib/Autoload.php';

final class ModuleWorkflowTest extends TestCase
{
    public function testCreateRequiresPaidWhmcsInvoiceBeforeCallingLumio(): void
    {
        [$workflow, $api, , $runtime] = $this->fixture();
        $runtime->latestInvoiceId = null;

        self::assertStringContainsString('paid WHMCS invoice', $workflow->createAccount());
        self::assertSame(0, $api->purchaseCalls);
    }

    public function testCreatePurchasesOnceStoresCredentialsAndNeverReportsSuccessTwice(): void
    {
        [$workflow, $api, $states, , $properties] = $this->fixture();
        $api->purchaseResults[] = ['operation_id' => 'op_' . str_repeat('a', 32)];
        $api->serviceResults[] = [
            'id' => 501,
            'number' => 'LUM-000501',
            'state' => 'active',
            'public_error' => null,
            'credentials_ready' => true,
        ];
        $api->credentialResults[] = [
            'username' => 'device-user',
            'password' => 'device-password',
            'ip_address' => '192.0.2.8',
            'hostname' => 'device.example.net',
            'connection_notes' => 'Use SSH',
        ];

        self::assertSame('success', $workflow->createAccount());
        self::assertSame(1, $api->purchaseCalls);
        self::assertSame('ready', $states->rows[1001]['delivery_state']);
        self::assertNotNull($states->rows[1001]['activation_reported_at']);
        self::assertNull($states->rows[1001]['next_poll_at']);
        self::assertSame('device-user', $properties->values['Username']);
        self::assertSame('device-password', $properties->values['Password']);
        self::assertSame('192.0.2.8', $properties->values['Dedicated IP']);

        $second = $workflow->createAccount();
        self::assertStringContainsString('cannot report it again', $second);
        self::assertSame(1, $api->purchaseCalls);
    }

    public function testTransportLossReusesPersistedPurchasePayloadAndReference(): void
    {
        [$workflow, $api, $states] = $this->fixture();
        $api->purchaseResults[] = new TransportException('timeout');
        $api->purchaseResults[] = ['operation_id' => 'op_' . str_repeat('b', 32)];
        $api->serviceResults[] = [
            'id' => 502,
            'number' => 'LUM-000502',
            'state' => 'active',
            'public_error' => null,
            'credentials_ready' => true,
        ];
        $api->credentialResults[] = ['username' => 'u', 'password' => 'p'];

        self::assertStringContainsString('unknown result', $workflow->createAccount());
        $firstPayload = $api->purchasePayloads[0];
        self::assertIsArray($states->rows[1001]['purchase_payload']);
        self::assertSame('success', $workflow->createAccount());
        self::assertSame($firstPayload, $api->purchasePayloads[1]);
        self::assertSame($api->purchaseKeys[0], $api->purchaseKeys[1]);
    }

    public function testOutOfStockLeavesNoLumioSuccessAndCanBeRetriedLater(): void
    {
        [$workflow, $api, $states] = $this->fixture();
        $api->purchaseResults[] = new ApiException(422, 'OUT_OF_STOCK', 'req-stock', null, 'out');

        $result = $workflow->createAccount();
        self::assertStringContainsString('inventory is currently unavailable', $result);
        self::assertNull($states->rows[1001]['purchase_payload']);
        self::assertSame('purchase_blocked', $states->rows[1001]['delivery_state']);
        self::assertSame('OUT_OF_STOCK', $states->rows[1001]['last_error_code']);
        self::assertNull($states->rows[1001]['next_poll_at']);
    }

    public function testProvisioningCreatePollsEveryFiveMinutesWithoutPurchasingAgain(): void
    {
        [$workflow, $api, $states] = $this->fixture([
            'poll_attempts' => 7,
            'next_poll_at' => '2026-08-02 00:00:00',
        ]);
        $api->purchaseResults[] = ['operation_id' => 'op_' . str_repeat('p', 32)];
        $api->serviceResults[] = [
            'id' => 503,
            'number' => 'LUM-000503',
            'state' => 'provisioning',
            'public_error' => null,
            'credentials_ready' => false,
        ];
        $api->serviceResults[] = [
            'id' => 503,
            'number' => 'LUM-000503',
            'state' => 'provisioning',
            'public_error' => null,
            'credentials_ready' => false,
        ];

        self::assertStringContainsString('cron will continue polling', $workflow->createAccount());
        self::assertSame(1, $api->purchaseCalls);
        self::assertSame(1, $states->rows[1001]['poll_attempts']);
        self::assertGreaterThanOrEqual(299, strtotime((string) $states->rows[1001]['next_poll_at']) - time());
        self::assertLessThanOrEqual(300, strtotime((string) $states->rows[1001]['next_poll_at']) - time());

        self::assertStringContainsString('cron will continue polling', $workflow->createAccount());
        self::assertSame(1, $api->purchaseCalls);
        self::assertGreaterThanOrEqual(299, strtotime((string) $states->rows[1001]['next_poll_at']) - time());
        self::assertLessThanOrEqual(300, strtotime((string) $states->rows[1001]['next_poll_at']) - time());
    }

    public function testTerminatedWhmcsServiceCannotBeRepurchased(): void
    {
        [$workflow, $api, , $runtime] = $this->fixture();
        $runtime->status = 'Terminated';
        self::assertStringContainsString('does not allow another Lumio purchase', $workflow->createAccount());
        self::assertSame(0, $api->purchaseCalls);
    }

    public function testActiveWhmcsStatusAcknowledgesPreviouslyReportedCreate(): void
    {
        [$workflow, , $states, $runtime] = $this->fixture([
            'activation_reported_at' => '2026-08-02 00:00:00',
        ]);
        $runtime->status = 'Active';

        self::assertStringContainsString('already active', $workflow->createAccount());
        self::assertNotNull($states->rows[1001]['activation_acknowledged_at']);
        self::assertNull($states->rows[1001]['next_poll_at']);
    }

    public function testProrataProductIsRejectedBeforePurchase(): void
    {
        [$workflow, $api, , $runtime] = $this->fixture();
        $runtime->compatibilityError = new ConfigurationException('Disable Prorata Billing');
        self::assertStringContainsString('Prorata Billing', $workflow->createAccount());
        self::assertSame(0, $api->purchaseCalls);
    }

    public function testRenewIsAnchoredToLatestPaidInvoiceAndDoesNotDoubleCharge(): void
    {
        [$workflow, $api, $states, $runtime] = $this->fixture([
            'lumio_service_id' => 501,
            'provisioning_invoice_id' => 10,
        ]);
        $runtime->latestInvoiceId = 20;
        $api->renewalQuoteResults[] = [
            'billing_cycle' => 'month',
            'total_cents' => 9900,
            'current_next_due_at' => '2026-09-02T00:00:00+08:00',
        ];
        $api->renewResults[] = ['operation_id' => 'op_' . str_repeat('c', 32)];

        self::assertSame('success', $workflow->renew());
        self::assertSame(20, $states->rows[1001]['last_renewal_invoice_id']);
        self::assertSame(1, $api->renewCalls);
        self::assertSame('success', $workflow->renew());
        self::assertSame(1, $api->renewCalls);
    }

    public function testRenewRejectsWhmcsAndLumioBillingCycleMismatchBeforeCharge(): void
    {
        [$workflow, $api, , $runtime] = $this->fixture([
            'lumio_service_id' => 501,
            'provisioning_invoice_id' => 10,
        ]);
        $runtime->latestInvoiceId = 20;
        $api->renewalQuoteResults[] = [
            'billing_cycle' => 'year',
            'total_cents' => 9900,
            'current_next_due_at' => '2026-09-02T00:00:00+08:00',
        ];

        self::assertStringContainsString('billing cycle', $workflow->renew());
        self::assertSame(0, $api->renewCalls);
    }

    public function testPendingRenewalCannotBeSilentlyReusedForAnotherInvoice(): void
    {
        [$workflow, $api, , $runtime] = $this->fixture([
            'lumio_service_id' => 501,
            'provisioning_invoice_id' => 10,
            'pending_action' => 'renew',
            'pending_invoice_id' => 19,
            'pending_external_reference' => 'whmcs-shop-example-01-service-1001-renew-invoice-19',
            'pending_payload' => [
                'external_reference' => 'whmcs-shop-example-01-service-1001-renew-invoice-19',
                'expected_next_due_at' => '2026-08-02T00:00:00+08:00',
                'expected_total_cents' => 10000,
            ],
        ]);
        $runtime->latestInvoiceId = 20;

        $result = $workflow->renew();
        self::assertStringContainsString('#19', $result);
        self::assertStringContainsString('#20', $result);
        self::assertSame(0, $api->renewCalls);
    }

    public function testCronRenewalReconciliationCannotStartANewRenewal(): void
    {
        [$workflow, $api, , $runtime] = $this->fixture([
            'lumio_service_id' => 501,
            'provisioning_invoice_id' => 10,
        ]);
        $runtime->latestInvoiceId = 20;

        self::assertSame('success', $workflow->renew(true));
        self::assertSame(0, $api->renewCalls);
        self::assertSame([], $api->renewalQuoteResults);
    }

    public function testDeterministicRenewalErrorStopsCronReconciliation(): void
    {
        [$workflow, $api, $states, $runtime] = $this->fixture([
            'lumio_service_id' => 501,
            'provisioning_invoice_id' => 10,
        ]);
        $runtime->latestInvoiceId = 20;
        $api->renewalQuoteResults[] = [
            'billing_cycle' => 'month',
            'total_cents' => 9900,
            'current_next_due_at' => '2026-09-02T00:00:00+08:00',
        ];
        $api->renewResults[] = new ApiException(
            409,
            'WALLET_INSUFFICIENT',
            'request-renew-wallet',
            null,
            'The Lumio wallet balance is insufficient',
        );

        self::assertStringContainsString('wallet balance is insufficient', $workflow->renew());
        self::assertNull($states->rows[1001]['pending_action']);
        self::assertNull($states->rows[1001]['next_poll_at']);
        self::assertSame('WALLET_INSUFFICIENT', $states->rows[1001]['last_error_code']);
        self::assertSame(1, $api->renewCalls);
    }

    public function testResumeWaitsUntilOtherLumioHoldsAreGone(): void
    {
        [$workflow, $api, $states, $runtime] = $this->fixture(['lumio_service_id' => 501]);
        $runtime->status = 'Suspended';
        $api->serviceDetailResults[] = ['id' => 501, 'state' => 'suspended', 'remaining_holds' => true];
        $api->lifecycleResults[] = ['operation_id' => 'op_' . str_repeat('d', 32)];
        $api->operationResults[] = [
            'status' => 'succeeded',
            'result' => ['service' => ['state' => 'suspended', 'remaining_holds' => true]],
        ];
        $api->operationResults[] = [
            'status' => 'succeeded',
            'result' => ['service' => ['state' => 'active', 'remaining_holds' => false]],
        ];

        self::assertStringContainsString('OTHER_HOLDS_REMAIN', $workflow->lifecycle('resume'));
        self::assertSame('resume', $states->rows[1001]['pending_action']);
        self::assertSame('success', $workflow->lifecycle('resume'));
        self::assertNull($states->rows[1001]['pending_action']);
        self::assertSame('active', $states->rows[1001]['delivery_state']);
        self::assertSame(1, $api->lifecycleCalls);

        self::assertStringContainsString('waiting for WHMCS', $workflow->lifecycle('resume'));
        self::assertSame(1, $api->lifecycleCalls);
    }

    public function testFailedLifecycleOperationClearsPendingStateForExplicitRetry(): void
    {
        [$workflow, $api, $states, $runtime] = $this->fixture(['lumio_service_id' => 501]);
        $runtime->status = 'Active';
        $api->serviceDetailResults[] = ['id' => 501, 'state' => 'active', 'remaining_holds' => false];
        $api->lifecycleResults[] = ['operation_id' => 'op_' . str_repeat('e', 32)];
        $api->operationResults[] = [
            'status' => 'failed',
            'result' => [
                'service' => ['public_error' => 'ACTION_FAILED'],
                'action' => [
                    'id' => 51,
                    'service_id' => 501,
                    'action' => 'suspend',
                    'status' => 'failed',
                    'credentials_state' => null,
                    'review_required' => true,
                    'completed_at' => '2026-08-02T08:00:00+00:00',
                ],
            ],
        ];

        self::assertStringContainsString('ACTION_FAILED', $workflow->lifecycle('suspend'));
        self::assertNull($states->rows[1001]['pending_action']);
        self::assertSame('ACTION_FAILED', $states->rows[1001]['last_error_code']);
    }

    public function testQueuedSuspendReturnsSuccessWhileCronKeepsPolling(): void
    {
        [$workflow, $api, $states, $runtime] = $this->fixture(['lumio_service_id' => 501]);
        $runtime->status = 'Active';
        $api->serviceDetailResults[] = ['id' => 501, 'state' => 'active', 'remaining_holds' => false];
        $api->lifecycleResults[] = ['operation_id' => 'op_' . str_repeat('q', 32)];
        $api->operationResults[] = ['status' => 'queued', 'result' => []];

        self::assertSame('success', $workflow->lifecycle('suspend'));
        self::assertSame('suspend', $states->rows[1001]['pending_action']);
        self::assertNotNull($states->rows[1001]['next_poll_at']);
        self::assertSame(1, $api->lifecycleCalls);
    }

    public function testQueuedResumeAndTerminateStillWaitForRemoteCompletion(): void
    {
        [$resume, $resumeApi, $resumeStates, $resumeRuntime] = $this->fixture(['lumio_service_id' => 501]);
        $resumeRuntime->status = 'Suspended';
        $resumeApi->serviceDetailResults[] = ['id' => 501, 'state' => 'suspended', 'remaining_holds' => true];
        $resumeApi->lifecycleResults[] = ['operation_id' => 'op_' . str_repeat('r', 32)];
        $resumeApi->operationResults[] = ['status' => 'queued', 'result' => []];

        self::assertStringContainsString('still processing', $resume->lifecycle('resume'));
        self::assertSame('resume', $resumeStates->rows[1001]['pending_action']);

        [$terminate, $terminateApi, $terminateStates, $terminateRuntime] = $this->fixture(['lumio_service_id' => 501]);
        $terminateRuntime->status = 'Active';
        $terminateApi->serviceDetailResults[] = ['id' => 501, 'state' => 'active', 'remaining_holds' => false];
        $terminateApi->lifecycleResults[] = ['operation_id' => 'op_' . str_repeat('t', 32)];
        $terminateApi->operationResults[] = ['status' => 'queued', 'result' => []];

        self::assertStringContainsString('still processing', $terminate->lifecycle('terminate'));
        self::assertSame('terminate', $terminateStates->rows[1001]['pending_action']);
    }

    public function testPersistedLifecycleOperationAlwaysPollsAgainInFiveMinutes(): void
    {
        [$workflow, $api, $states, $runtime] = $this->fixture([
            'lumio_service_id' => 501,
            'pending_action' => 'resume',
            'pending_external_reference' => 'whmcs-shop-example-01-service-1001-resume-1',
            'pending_operation_id' => 'op_' . str_repeat('p', 32),
            'pending_payload' => ['external_reference' => 'whmcs-shop-example-01-service-1001-resume-1'],
            'poll_attempts' => 8,
        ]);
        $runtime->status = 'Suspended';
        $api->operationResults[] = ['status' => 'processing', 'result' => []];

        self::assertStringContainsString('still processing', $workflow->lifecycle('resume'));
        self::assertSame(9, $states->rows[1001]['poll_attempts']);
        self::assertGreaterThanOrEqual(299, strtotime((string) $states->rows[1001]['next_poll_at']) - time());
        self::assertLessThanOrEqual(300, strtotime((string) $states->rows[1001]['next_poll_at']) - time());
        self::assertSame(0, $api->lifecycleCalls);
    }

    public function testFailedAcceptedSuspendSchedulesWhmcsStatusRollback(): void
    {
        [$workflow, $api, $states, $runtime] = $this->fixture([
            'lumio_service_id' => 501,
            'pending_action' => 'suspend',
            'pending_external_reference' => 'whmcs-shop-example-01-service-1001-suspend-1',
            'pending_operation_id' => 'op_' . str_repeat('f', 32),
            'pending_payload' => ['external_reference' => 'whmcs-shop-example-01-service-1001-suspend-1'],
        ]);
        $runtime->status = 'Suspended';
        $api->operationResults[] = [
            'status' => 'failed',
            'result' => ['service' => ['public_error' => 'ACTION_FAILED']],
        ];

        self::assertStringContainsString('ACTION_FAILED', $workflow->lifecycle('suspend'));
        self::assertSame('suspend_rollback', $states->rows[1001]['pending_action']);
        self::assertSame('ACTION_FAILED', $states->rows[1001]['last_error_code']);
    }

    public function testLifecycleFailureDoesNotDependOnUndocumentedActionErrorCode(): void
    {
        [$workflow] = $this->fixture();
        $method = new \ReflectionMethod(ModuleWorkflow::class, 'operationPublicError');

        self::assertSame('ACTION_FAILED', $method->invoke($workflow, [
            'status' => 'failed',
            'result' => ['action' => ['error_code' => 'INTERNAL_DEVICE_FAILURE']],
        ]));
    }

    public function testMatchingLocalStatusDoesNotAbandonPersistedRemoteLifecycleOperation(): void
    {
        [$workflow, $api, $states, $runtime] = $this->fixture([
            'lumio_service_id' => 501,
            'pending_action' => 'suspend',
            'pending_external_reference' => 'whmcs-shop-example-01-service-1001-suspend-1',
            'pending_operation_id' => 'op_' . str_repeat('f', 32),
            'pending_payload' => ['external_reference' => 'whmcs-shop-example-01-service-1001-suspend-1'],
            'action_sequence' => 1,
        ]);
        $runtime->status = 'Suspended';
        $api->operationResults[] = [
            'status' => 'succeeded',
            'result' => ['service' => ['state' => 'suspended', 'remaining_holds' => false]],
        ];

        self::assertSame('success', $workflow->lifecycle('suspend'));
        self::assertNull($states->rows[1001]['pending_action']);
        self::assertSame('suspended', $states->rows[1001]['delivery_state']);
        self::assertSame(0, $api->lifecycleCalls);
    }

    public function testMatchingWhmcsStatusCannotHideDifferentLumioState(): void
    {
        [$workflow, $api, , $runtime] = $this->fixture(['lumio_service_id' => 501]);
        $runtime->status = 'Suspended';
        $api->serviceDetailResults[] = ['id' => 501, 'state' => 'active', 'remaining_holds' => false];
        $api->lifecycleResults[] = ['operation_id' => 'op_' . str_repeat('g', 32)];
        $api->operationResults[] = [
            'status' => 'succeeded',
            'result' => ['service' => ['state' => 'suspended', 'remaining_holds' => true]],
        ];

        self::assertSame('success', $workflow->lifecycle('suspend'));
        self::assertSame(1, $api->serviceCalls);
        self::assertSame(1, $api->lifecycleCalls);
    }

    public function testRemoteLumioTargetStateAvoidsDuplicateLifecycleAction(): void
    {
        [$workflow, $api, , $runtime] = $this->fixture(['lumio_service_id' => 501]);
        $runtime->status = 'Active';
        $api->serviceDetailResults[] = ['id' => 501, 'state' => 'suspended', 'remaining_holds' => true];

        self::assertSame('success', $workflow->lifecycle('suspend'));
        self::assertSame(1, $api->serviceCalls);
        self::assertSame(0, $api->lifecycleCalls);
    }

    /**
     * @param array<string, mixed> $initialState
     * @return array{ModuleWorkflow, WorkflowTestApi, WorkflowTestStates, WorkflowTestRuntime, WorkflowTestProperties}
     */
    private function fixture(array $initialState = []): array
    {
        $api = new WorkflowTestApi();
        $states = new WorkflowTestStates($initialState);
        $runtime = new WorkflowTestRuntime();
        $properties = new WorkflowTestProperties();
        $configuration = new Configuration([
            'serverhostname' => 'api.example.com',
            'serverusername' => 'shop-example-01',
            'serverpassword' => 'lumio_live_' . str_repeat('a', 24) . '.' . str_repeat('b', 43),
            'serversecure' => true,
            'serverport' => 443,
            'billingcycle' => 'Monthly',
            'configoption1' => 'example-product-a',
            'configoption2' => '',
            'configoption3' => '',
            'configoption4' => '10000',
            'configoption10' => 'on',
            'configoption11' => '0',
            'configoption12' => 'on',
        ]);
        return [
            new ModuleWorkflow(1001, $configuration, $api, $states, $runtime, $properties, new WorkflowTestLogger()),
            $api,
            $states,
            $runtime,
            $properties,
        ];
    }
}

final class WorkflowTestApi implements ApiClientInterface
{
    /** @var list<array<string, mixed>|\Throwable> */
    public array $purchaseResults = [];
    /** @var list<array<string, mixed>|\Throwable> */
    public array $serviceResults = [];
    /** @var list<array<string, mixed>|\Throwable> */
    public array $serviceDetailResults = [];
    /** @var list<array<string, mixed>|\Throwable> */
    public array $credentialResults = [];
    /** @var list<array<string, mixed>|\Throwable> */
    public array $operationResults = [];
    /** @var list<array<string, mixed>|\Throwable> */
    public array $renewalQuoteResults = [];
    /** @var list<array<string, mixed>|\Throwable> */
    public array $renewResults = [];
    /** @var list<array<string, mixed>|\Throwable> */
    public array $lifecycleResults = [];
    /** @var list<array<string, mixed>> */
    public array $purchasePayloads = [];
    /** @var list<string> */
    public array $purchaseKeys = [];
    public int $purchaseCalls = 0;
    public int $renewCalls = 0;
    public int $lifecycleCalls = 0;
    public int $serviceCalls = 0;

    public function account(): array { return []; }
    public function catalog(): array { return []; }

    public function product(string $sku): array { return []; }

    public function purchase(array $payload, string $idempotencyKey): array
    {
        ++$this->purchaseCalls;
        $this->purchasePayloads[] = $payload;
        $this->purchaseKeys[] = $idempotencyKey;
        return $this->next($this->purchaseResults);
    }

    public function serviceByReference(string $externalReference): array
    {
        return $this->next($this->serviceResults);
    }

    public function service(int $serviceId): array
    {
        ++$this->serviceCalls;
        return $this->next($this->serviceDetailResults);
    }

    public function credentials(int $serviceId): array
    {
        return $this->next($this->credentialResults);
    }

    public function operation(string $operationId): array
    {
        return $this->next($this->operationResults);
    }

    public function renewalQuote(int $serviceId): array
    {
        return $this->next($this->renewalQuoteResults);
    }

    public function renew(int $serviceId, array $payload, string $idempotencyKey): array
    {
        ++$this->renewCalls;
        return $this->next($this->renewResults);
    }

    public function lifecycle(int $serviceId, string $action, array $payload, string $idempotencyKey): array
    {
        ++$this->lifecycleCalls;
        return $this->next($this->lifecycleResults);
    }

    public function lastRequestId(): ?string { return 'request-test-1'; }

    /** @param list<array<string, mixed>|\Throwable> $queue @return array<string, mixed> */
    private function next(array &$queue): array
    {
        $result = array_shift($queue);
        if ($result instanceof \Throwable) {
            throw $result;
        }
        if (! is_array($result)) {
            throw new \RuntimeException('Test API queue is empty');
        }
        return $result;
    }
}

final class WorkflowTestStates implements StateRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    /** @param array<string, mixed> $initial */
    public function __construct(array $initial = [])
    {
        $this->rows[1001] = $initial + $this->defaults(1001);
    }

    public function ensureSchema(): void {}
    public function get(int $serviceId): array { return $this->rows[$serviceId] ?? $this->defaults($serviceId); }
    public function save(int $serviceId, array $changes): void { $this->rows[$serviceId] = $changes + $this->get($serviceId); }
    public function pendingLifecycle(int $limit): array { return []; }

    /** @return array<string, mixed> */
    private function defaults(int $serviceId): array
    {
        return [
            'service_id' => $serviceId,
            'purchase_external_reference' => null,
            'purchase_operation_id' => null,
            'purchase_payload' => null,
            'lumio_service_id' => null,
            'lumio_service_number' => null,
            'delivery_state' => 'pending',
            'activation_reported_at' => null,
            'activation_acknowledged_at' => null,
            'provisioning_invoice_id' => null,
            'last_renewal_invoice_id' => null,
            'pending_invoice_id' => null,
            'pending_action' => null,
            'pending_external_reference' => null,
            'pending_operation_id' => null,
            'pending_payload' => null,
            'action_sequence' => 0,
            'last_completed_action' => null,
            'last_completed_at' => null,
            'last_request_id' => null,
            'last_error_code' => null,
            'last_error_message' => null,
            'poll_attempts' => 0,
            'next_poll_at' => null,
        ];
    }
}

final class WorkflowTestRuntime implements RuntimeInterface
{
    public ?int $latestInvoiceId = 10;
    public ?string $status = 'Pending';
    public ?\Throwable $compatibilityError = null;

    public function withServiceLock(int $serviceId, callable $callback): mixed { return $callback(); }
    public function withCronLock(callable $callback): mixed { return $callback(); }
    public function latestPaidHostingInvoiceId(int $serviceId): ?int { return $this->latestInvoiceId; }
    public function serviceStatus(int $serviceId): ?string { return $this->status; }
    public function restoreActiveStatusAfterFailedSuspend(int $serviceId): void {}
    public function assertProductCompatible(int $serviceId): void
    {
        if ($this->compatibilityError !== null) {
            throw $this->compatibilityError;
        }
    }
    public function pendingCreateServiceIds(int $limit): array { return []; }
    public function runModuleCommand(string $command, int $serviceId): array { return ['result' => 'success']; }
}

final class WorkflowTestProperties implements ServicePropertiesInterface
{
    /** @var array<string, int|string> */
    public array $values = [];
    public function get(string $name): ?string { return isset($this->values[$name]) ? (string) $this->values[$name] : null; }
    public function save(array $values): void { $this->values = $values + $this->values; }
}

final class WorkflowTestLogger implements LoggerInterface
{
    public function apiCall(string $action, array $request, array $response): void {}
    public function activity(string $message): void {}
}
