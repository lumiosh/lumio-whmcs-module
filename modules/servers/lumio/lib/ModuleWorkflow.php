<?php

declare(strict_types=1);

namespace Lumio\Whmcs;

use Lumio\Whmcs\Contract\ApiClientInterface;
use Lumio\Whmcs\Contract\LoggerInterface;
use Lumio\Whmcs\Contract\RuntimeInterface;
use Lumio\Whmcs\Contract\ServicePropertiesInterface;
use Lumio\Whmcs\Contract\StateRepositoryInterface;
use Lumio\Whmcs\Exception\ApiException;
use Lumio\Whmcs\Exception\ConfigurationException;
use Lumio\Whmcs\Exception\TransportException;
use Lumio\Whmcs\Support\Sanitizer;

final class ModuleWorkflow
{
    private const RECONCILIATION_INTERVAL_SECONDS = 300;

    private const CREATE_POLL_SECONDS = 300;

    public const PROPERTY_EXTERNAL_REFERENCE = 'Lumio External Reference';
    public const PROPERTY_OPERATION_ID = 'Lumio Operation ID';
    public const PROPERTY_SERVICE_ID = 'Lumio Service ID';
    public const PROPERTY_SERVICE_NUMBER = 'Lumio Service Number';
    public const PROPERTY_DELIVERY_STATE = 'Lumio Delivery State';
    public const PROPERTY_LAST_REQUEST_ID = 'Lumio Last Request ID';
    public const PROPERTY_LAST_ERROR = 'Lumio Last Error';
    public const PROPERTY_PROVISIONING_INVOICE_ID = 'Lumio Provisioning Invoice ID';
    public const PROPERTY_LAST_RENEWAL_INVOICE_ID = 'Lumio Last Renewal Invoice ID';

    private const RECOVERABLE_PURCHASE_ERRORS = [
        'PRODUCT_NOT_FOUND',
        'INVALID_SELECTION',
        'OUT_OF_STOCK',
        'PRICE_CHANGED',
        'WALLET_INSUFFICIENT',
        'WALLET_RESTRICTED',
    ];

    private const SERVICE_STATES = [
        'paid_pending_service',
        'provisioning',
        'needs_attention',
        'active',
        'suspended',
        'terminating',
        'terminated',
        'deleted',
    ];

    private const OPERATION_STATES = ['queued', 'processing', 'succeeded', 'needs_attention', 'failed'];

    public function __construct(
        private readonly int $serviceId,
        private readonly Configuration $configuration,
        private readonly ApiClientInterface $api,
        private readonly StateRepositoryInterface $states,
        private readonly RuntimeInterface $runtime,
        private readonly ServicePropertiesInterface $properties,
        private readonly LoggerInterface $logger,
    ) {
        if ($serviceId < 1) {
            throw new \InvalidArgumentException('The WHMCS service ID is invalid');
        }
    }

    public function createAccount(): string
    {
        return $this->execute('CreateAccount', function (): string {
            $this->runtime->assertProductCompatible($this->serviceId);
            $this->configuration->assertTerminationPolicyAccepted();
            $state = $this->states->get($this->serviceId);
            $status = strtolower((string) $this->runtime->serviceStatus($this->serviceId));
            if ($status === 'active') {
                if (($state['activation_reported_at'] ?? null) !== null
                    && ($state['activation_acknowledged_at'] ?? null) === null) {
                    $this->states->save($this->serviceId, [
                        'activation_acknowledged_at' => gmdate('Y-m-d H:i:s'),
                        'poll_attempts' => 0,
                        'next_poll_at' => null,
                    ]);
                }
                return 'The Lumio service is already active and CreateAccount cannot be run again';
            }
            if ($status !== 'pending') {
                return sprintf('The current WHMCS service status is %s and does not allow another Lumio purchase', $status === '' ? 'unknown' : $status);
            }

            if (($state['activation_reported_at'] ?? null) !== null
                || ($state['activation_acknowledged_at'] ?? null) !== null) {
                return 'Lumio has already reported a successful activation to WHMCS and cannot report it again; if the service is still Pending, review the WHMCS Module Queue and Activity Log';
            }

            $paidInvoiceId = $this->runtime->latestPaidHostingInvoiceId($this->serviceId);
            if ($paidInvoiceId === null) {
                return 'No paid WHMCS invoice was found for this service; Lumio will not charge the wallet in advance';
            }

            if (($state['provisioning_invoice_id'] ?? null) === null) {
                $this->states->save($this->serviceId, ['provisioning_invoice_id' => $paidInvoiceId]);
                $this->properties->save([self::PROPERTY_PROVISIONING_INVOICE_ID => $paidInvoiceId]);
                $state['provisioning_invoice_id'] = $paidInvoiceId;
            }

            $externalReference = $this->stringOrNull($state['purchase_external_reference'] ?? null)
                ?? $this->configuration->externalReference($this->serviceId, 'create', 1);
            $payload = is_array($state['purchase_payload'] ?? null)
                ? $state['purchase_payload']
                : $this->configuration->purchasePayload($externalReference);

            if (! is_array($state['purchase_payload'] ?? null)) {
                $this->states->save($this->serviceId, [
                    'purchase_external_reference' => $externalReference,
                    'purchase_payload' => $payload,
                    'delivery_state' => 'purchasing',
                    'poll_attempts' => 0,
                    'next_poll_at' => null,
                    'last_error_code' => null,
                    'last_error_message' => null,
                ]);
                $this->properties->save([
                    self::PROPERTY_EXTERNAL_REFERENCE => $externalReference,
                    self::PROPERTY_DELIVERY_STATE => 'purchasing',
                ]);
            }

            $operationId = $this->stringOrNull($state['purchase_operation_id'] ?? null);
            if ($operationId === null) {
                try {
                    $purchase = $this->api->purchase(
                        $payload,
                        $this->configuration->idempotencyKey($externalReference),
                    );
                } catch (ApiException $exception) {
                    if (in_array($exception->errorCode, self::RECOVERABLE_PURCHASE_ERRORS, true)) {
                        $this->states->save($this->serviceId, [
                            'purchase_payload' => null,
                            'delivery_state' => 'purchase_blocked',
                            'poll_attempts' => 0,
                            'next_poll_at' => null,
                            'last_error_code' => $exception->errorCode,
                            'last_error_message' => $this->errorSummary($exception),
                            'last_request_id' => $exception->requestId,
                        ]);
                        $this->properties->save([
                            self::PROPERTY_DELIVERY_STATE => 'purchase_blocked',
                            self::PROPERTY_LAST_ERROR => $exception->errorCode,
                            self::PROPERTY_LAST_REQUEST_ID => (string) $exception->requestId,
                        ]);
                    }
                    throw $exception;
                }
                $operationId = $this->requiredOperationId($purchase['operation_id'] ?? null);
                $this->states->save($this->serviceId, [
                    'purchase_operation_id' => $operationId,
                    'delivery_state' => 'paid_pending_service',
                    'last_request_id' => $this->api->lastRequestId(),
                    'last_error_code' => null,
                    'last_error_message' => null,
                    'poll_attempts' => 0,
                    'next_poll_at' => null,
                ]);
                $this->properties->save([
                    self::PROPERTY_OPERATION_ID => $operationId,
                    self::PROPERTY_DELIVERY_STATE => 'paid_pending_service',
                    self::PROPERTY_LAST_REQUEST_ID => (string) $this->api->lastRequestId(),
                ]);
            }

            try {
                $service = $this->api->serviceByReference($externalReference);
            } catch (ApiException $exception) {
                if (in_array($exception->errorCode, ['SERVICE_NOT_FOUND', 'PURCHASE_NOT_FOUND'], true)) {
                    $this->scheduleCreateRetry();
                    return $this->pending('paid_pending_service', null, $exception->requestId);
                }
                throw $exception;
            }

            $serviceState = strtolower(Sanitizer::text((string) ($service['state'] ?? 'provisioning'), 32));
            if (! in_array($serviceState, self::SERVICE_STATES, true)) {
                throw new TransportException('The Lumio API returned an unknown service state', $this->api->lastRequestId());
            }
            $publicError = isset($service['public_error']) && is_string($service['public_error'])
                ? Sanitizer::errorCode($service['public_error'])
                : null;
            $lumioServiceId = isset($service['id']) ? (int) $service['id'] : 0;
            $serviceNumber = Sanitizer::text((string) ($service['number'] ?? ''), 64);
            $this->states->save($this->serviceId, [
                'lumio_service_id' => $lumioServiceId > 0 ? $lumioServiceId : null,
                'lumio_service_number' => $serviceNumber === '' ? null : $serviceNumber,
                'delivery_state' => $serviceState,
                'last_request_id' => $this->api->lastRequestId(),
                'last_error_code' => $publicError,
                'last_error_message' => $publicError,
            ]);
            $propertyValues = [
                self::PROPERTY_DELIVERY_STATE => $serviceState,
                self::PROPERTY_LAST_REQUEST_ID => (string) $this->api->lastRequestId(),
                self::PROPERTY_LAST_ERROR => (string) $publicError,
            ];
            if ($lumioServiceId > 0) {
                $propertyValues[self::PROPERTY_SERVICE_ID] = $lumioServiceId;
            }
            if ($serviceNumber !== '') {
                $propertyValues[self::PROPERTY_SERVICE_NUMBER] = $serviceNumber;
            }
            $this->properties->save($propertyValues);

            if ($serviceState === 'needs_attention') {
                $this->scheduleCreateRetry($publicError ?? 'NEEDS_ATTENTION');
                return $this->pending($serviceState, $publicError, $this->api->lastRequestId());
            }
            if ($serviceState !== 'active' || ($service['credentials_ready'] ?? false) !== true || $lumioServiceId < 1) {
                $this->scheduleCreateRetry();
                return $this->pending($serviceState, $publicError, $this->api->lastRequestId());
            }

            $credentials = $this->api->credentials($lumioServiceId);
            $username = trim((string) ($credentials['username'] ?? ''));
            $password = (string) ($credentials['password'] ?? '');
            if ($username === '' || $password === '') {
                $this->scheduleCreateRetry('CREDENTIALS_NOT_READY');
                return $this->pending('active', 'CREDENTIALS_NOT_READY', $this->api->lastRequestId());
            }

            $credentialProperties = [
                'Username' => $username,
                'Password' => $password,
                self::PROPERTY_DELIVERY_STATE => 'ready',
                self::PROPERTY_LAST_REQUEST_ID => (string) $this->api->lastRequestId(),
                self::PROPERTY_LAST_ERROR => '',
            ];
            $ipAddress = trim((string) ($credentials['ip_address'] ?? ''));
            if (filter_var($ipAddress, FILTER_VALIDATE_IP) !== false) {
                $credentialProperties['Dedicated IP'] = $ipAddress;
            }
            $hostname = Sanitizer::text((string) ($credentials['hostname'] ?? ''), 253);
            if ($hostname !== '') {
                $credentialProperties['Lumio Hostname'] = $hostname;
            }
            $connectionNotes = Sanitizer::text((string) ($credentials['connection_notes'] ?? ''), 240);
            if ($connectionNotes !== '') {
                $credentialProperties['Lumio Connection Notes'] = $connectionNotes;
            }
            $this->properties->save($credentialProperties);
            $this->states->save($this->serviceId, [
                'delivery_state' => 'ready',
                'activation_reported_at' => gmdate('Y-m-d H:i:s'),
                'last_request_id' => $this->api->lastRequestId(),
                'last_error_code' => null,
                'last_error_message' => null,
                'poll_attempts' => 0,
                'next_poll_at' => null,
            ]);
            return 'success';
        });
    }

    public function renew(bool $pendingOnly = false): string
    {
        return $this->execute('Renew', function () use ($pendingOnly): string {
            $this->runtime->assertProductCompatible($this->serviceId);
            $this->configuration->assertTerminationPolicyAccepted();
            $state = $this->states->get($this->serviceId);
            $pendingAction = $state['pending_action'] ?? null;
            if ($pendingOnly && $pendingAction !== 'renew') {
                return 'success';
            }
            $lumioServiceId = $this->lumioServiceId($state);
            $invoiceId = $this->runtime->latestPaidHostingInvoiceId($this->serviceId);
            if ($invoiceId === null) {
                return 'No paid WHMCS renewal invoice was found for this service; Lumio will not charge the wallet';
            }
            if ((int) ($state['provisioning_invoice_id'] ?? 0) === $invoiceId) {
                return 'Only the original provisioning invoice exists; no new paid renewal invoice was found';
            }
            if ((int) ($state['last_renewal_invoice_id'] ?? 0) === $invoiceId) {
                return 'success';
            }
            if ($pendingAction !== null && $pendingAction !== 'renew') {
                return 'Another Lumio lifecycle operation is still pending; retry the renewal later';
            }

            $pendingInvoiceId = isset($state['pending_invoice_id']) ? (int) $state['pending_invoice_id'] : 0;
            if ($pendingAction === 'renew'
                && $pendingInvoiceId > 0
                && $pendingInvoiceId !== $invoiceId) {
                return sprintf(
                    'The Lumio result for WHMCS renewal invoice #%d is still pending; invoice #%d cannot be used until the previous renewal is retried',
                    $pendingInvoiceId,
                    $invoiceId,
                );
            }

            $externalReference = $this->stringOrNull($state['pending_external_reference'] ?? null);
            $payload = is_array($state['pending_payload'] ?? null) ? $state['pending_payload'] : null;
            if ($pendingAction !== 'renew' || $externalReference === null || $payload === null) {
                $quote = $this->api->renewalQuote($lumioServiceId);
                $configuredCycle = $this->configuration->billingCycle();
                $quotedCycle = strtolower(trim((string) ($quote['billing_cycle'] ?? '')));
                if ($quotedCycle !== $configuredCycle) {
                    throw new ConfigurationException(sprintf(
                        'The current WHMCS billing cycle (%s) does not match the Lumio service cycle (%s); correct the product or service billing cycle first',
                        $configuredCycle,
                        $quotedCycle === '' ? 'unknown' : $quotedCycle,
                    ));
                }
                $cap = $this->configuration->costCapCents();
                $quotedTotal = (int) ($quote['total_cents'] ?? -1);
                if ($quotedTotal < 0 || $quotedTotal > $cap) {
                    throw new ApiException(
                        409,
                        'PRICE_CHANGED',
                        $this->api->lastRequestId(),
                        null,
                        'The Lumio renewal amount exceeds the cost cap configured for this WHMCS product',
                    );
                }
                $expectedDueAt = trim((string) ($quote['current_next_due_at'] ?? ''));
                if ($expectedDueAt === '') {
                    throw new TransportException('The Lumio renewal quote is missing the current due date', $this->api->lastRequestId());
                }
                $externalReference = $this->configuration->externalReference(
                    $this->serviceId,
                    'renew-invoice',
                    $invoiceId,
                );
                $payload = [
                    'external_reference' => $externalReference,
                    'expected_next_due_at' => $expectedDueAt,
                    'expected_total_cents' => $cap,
                ];
                $this->states->save($this->serviceId, [
                    'pending_action' => 'renew',
                    'pending_invoice_id' => $invoiceId,
                    'pending_external_reference' => $externalReference,
                    'pending_operation_id' => null,
                    'pending_payload' => $payload,
                    'last_error_code' => null,
                    'last_error_message' => null,
                ]);
            }

            try {
                $result = $this->api->renew(
                    $lumioServiceId,
                    $payload,
                    $this->configuration->idempotencyKey($externalReference),
                );
            } catch (ApiException $exception) {
                $this->clearPending();
                throw $exception;
            }
            $operationId = $this->requiredOperationId($result['operation_id'] ?? null);
            $this->states->save($this->serviceId, [
                'last_renewal_invoice_id' => $invoiceId,
                'pending_invoice_id' => null,
                'pending_action' => null,
                'pending_external_reference' => null,
                'pending_operation_id' => null,
                'pending_payload' => null,
                'last_request_id' => $this->api->lastRequestId(),
                'last_error_code' => null,
                'last_error_message' => null,
            ]);
            $this->properties->save([
                self::PROPERTY_OPERATION_ID => $operationId,
                self::PROPERTY_LAST_RENEWAL_INVOICE_ID => $invoiceId,
                self::PROPERTY_LAST_REQUEST_ID => (string) $this->api->lastRequestId(),
                self::PROPERTY_LAST_ERROR => '',
            ]);
            return 'success';
        });
    }

    public function lifecycle(string $action): string
    {
        if (! in_array($action, ['suspend', 'resume', 'terminate'], true)) {
            throw new \InvalidArgumentException('The Lumio lifecycle action is not supported');
        }

        return $this->execute(ucfirst($action), function () use ($action): string {
            $this->runtime->assertProductCompatible($this->serviceId);
            $this->configuration->assertTerminationPolicyAccepted();
            $state = $this->states->get($this->serviceId);
            $lumioServiceId = $this->lumioServiceId($state);
            $pendingAction = $this->stringOrNull($state['pending_action'] ?? null);
            $lastCompletedAt = strtotime((string) ($state['last_completed_at'] ?? ''));
            if ($pendingAction === null
                && ($state['last_completed_action'] ?? null) === $action
                && $lastCompletedAt !== false
                && $lastCompletedAt + 120 > time()) {
                return sprintf('Lumio %s succeeded and is waiting for WHMCS to finish the local status update', $action);
            }
            if ($pendingAction !== null && $pendingAction !== $action) {
                return sprintf('Lumio %s is still pending and %s cannot run at the same time', $pendingAction, $action);
            }

            if ($pendingAction === null) {
                $service = $this->api->service($lumioServiceId);
                $serviceState = strtolower(Sanitizer::text((string) ($service['state'] ?? ''), 32));
                if (! in_array($serviceState, self::SERVICE_STATES, true)) {
                    throw new TransportException('The Lumio API returned an unknown service state', $this->api->lastRequestId());
                }
                $this->states->save($this->serviceId, [
                    'delivery_state' => $serviceState,
                    'last_request_id' => $this->api->lastRequestId(),
                ]);
                $this->properties->save([
                    self::PROPERTY_DELIVERY_STATE => $serviceState,
                    self::PROPERTY_LAST_REQUEST_ID => (string) $this->api->lastRequestId(),
                ]);
                if ($this->serviceReachedTarget($action, $service)) {
                    return 'success';
                }
            }

            $externalReference = $this->stringOrNull($state['pending_external_reference'] ?? null);
            $payload = is_array($state['pending_payload'] ?? null) ? $state['pending_payload'] : null;
            $operationId = $this->stringOrNull($state['pending_operation_id'] ?? null);
            if ($pendingAction !== $action || $externalReference === null || $payload === null) {
                $sequence = (int) ($state['action_sequence'] ?? 0) + 1;
                $externalReference = $this->configuration->externalReference(
                    $this->serviceId,
                    $action,
                    $sequence,
                );
                $payload = ['external_reference' => $externalReference];
                if ($action === 'terminate') {
                    $payload['immediate'] = true;
                }
                $operationId = null;
                $this->states->save($this->serviceId, [
                    'pending_action' => $action,
                    'pending_external_reference' => $externalReference,
                    'pending_operation_id' => null,
                    'pending_payload' => $payload,
                    'action_sequence' => $sequence,
                    'poll_attempts' => 0,
                    'next_poll_at' => null,
                    'last_error_code' => null,
                    'last_error_message' => null,
                ]);
            }

            if ($operationId === null) {
                $result = $this->api->lifecycle(
                    $lumioServiceId,
                    $action,
                    $payload,
                    $this->configuration->idempotencyKey($externalReference),
                );
                $operationId = $this->requiredOperationId($result['operation_id'] ?? null);
                $this->states->save($this->serviceId, [
                    'pending_operation_id' => $operationId,
                    'last_request_id' => $this->api->lastRequestId(),
                ]);
                $this->properties->save([
                    self::PROPERTY_OPERATION_ID => $operationId,
                    self::PROPERTY_LAST_REQUEST_ID => (string) $this->api->lastRequestId(),
                ]);
            }

            $operation = $this->api->operation($operationId);
            $status = strtolower(trim((string) ($operation['status'] ?? 'processing')));
            if (! in_array($status, self::OPERATION_STATES, true)) {
                throw new TransportException('The Lumio API returned an unknown operation state', $this->api->lastRequestId());
            }
            if ($status === 'succeeded') {
                if (! $this->lifecycleReachedTarget($action, $operation)) {
                    $this->scheduleRetry();
                    if ($action === 'suspend') {
                        return 'success';
                    }
                    $publicError = $action === 'resume' ? 'OTHER_HOLDS_REMAIN' : null;
                    return $this->pending('waiting_for_target_state', $publicError, $this->api->lastRequestId());
                }
                $completedDeliveryState = match ($action) {
                    'suspend' => 'suspended',
                    'resume' => 'active',
                    'terminate' => 'terminated',
                };
                $this->clearPending();
                $this->states->save($this->serviceId, [
                    'delivery_state' => $completedDeliveryState,
                    'last_completed_action' => $action,
                    'last_completed_at' => gmdate('Y-m-d H:i:s'),
                ]);
                $this->properties->save([
                    self::PROPERTY_DELIVERY_STATE => $completedDeliveryState,
                    self::PROPERTY_LAST_REQUEST_ID => (string) $this->api->lastRequestId(),
                    self::PROPERTY_LAST_ERROR => '',
                ]);
                return 'success';
            }

            if ($status === 'failed') {
                $publicError = $this->operationPublicError($operation) ?? 'ACTION_FAILED';
                if ($action === 'suspend'
                    && strtolower((string) $this->runtime->serviceStatus($this->serviceId)) === 'suspended') {
                    $this->states->save($this->serviceId, [
                        'pending_action' => 'suspend_rollback',
                        'poll_attempts' => 0,
                        'next_poll_at' => null,
                    ]);
                } else {
                    $this->clearPending();
                }
                $this->recordError($publicError, $this->api->lastRequestId(), $publicError);
                return $this->pending($status, $publicError, $this->api->lastRequestId());
            }

            $publicError = $this->operationPublicError($operation);
            $this->scheduleRetry($publicError);
            if ($action === 'suspend'
                && $publicError === null
                && in_array($status, ['queued', 'processing'], true)) {
                return 'success';
            }
            return $this->pending($status, $publicError, $this->api->lastRequestId());
        });
    }

    private function execute(string $action, callable $callback): string
    {
        try {
            $this->states->ensureSchema();
            return $this->runtime->withServiceLock($this->serviceId, $callback);
        } catch (ConfigurationException $exception) {
            return 'Lumio configuration error: ' . Sanitizer::text($exception->getMessage());
        } catch (ApiException $exception) {
            $this->recordError($exception->errorCode, $exception->requestId, $this->errorSummary($exception));
            if ($action === 'CreateAccount'
                && ! in_array($exception->errorCode, self::RECOVERABLE_PURCHASE_ERRORS, true)) {
                $this->scheduleCreateRetry($exception->errorCode);
            }
            return $this->humanApiError($exception);
        } catch (TransportException $exception) {
            $this->recordError('TRANSPORT_ERROR', $exception->requestId, 'The network result is unknown; safely retry with the original idempotency reference');
            if ($action === 'CreateAccount') {
                $this->scheduleCreateRetry('TRANSPORT_ERROR');
            }
            return $this->withRequestId('The Lumio network request failed or returned an unknown result; retry safely later', $exception->requestId);
        } catch (\Throwable $exception) {
            $this->logger->activity($action . ' failed: ' . Sanitizer::text($exception->getMessage()));
            $this->recordError('MODULE_ERROR', null, 'Internal WHMCS module error');
            return 'Internal Lumio module error; review the Activity Log and Module Log';
        }
    }

    /** @param array<string, mixed> $state */
    private function lumioServiceId(array $state): int
    {
        $id = (int) ($state['lumio_service_id'] ?? $this->properties->get(self::PROPERTY_SERVICE_ID) ?? 0);
        if ($id < 1) {
            throw new ConfigurationException('This WHMCS service is not linked to a Lumio Service ID');
        }
        return $id;
    }

    private function clearPending(): void
    {
        $this->states->save($this->serviceId, [
            'pending_action' => null,
            'pending_invoice_id' => null,
            'pending_external_reference' => null,
            'pending_operation_id' => null,
            'pending_payload' => null,
            'poll_attempts' => 0,
            'next_poll_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
        ]);
    }

    /** @param array<string, mixed> $operation */
    private function lifecycleReachedTarget(string $action, array $operation): bool
    {
        $result = is_array($operation['result'] ?? null) ? $operation['result'] : [];
        $service = is_array($result['service'] ?? null) ? $result['service'] : [];
        return $this->serviceReachedTarget($action, $service);
    }

    /** @param array<string, mixed> $service */
    private function serviceReachedTarget(string $action, array $service): bool
    {
        $state = strtolower(trim((string) ($service['state'] ?? '')));
        return match ($action) {
            'suspend' => $state === 'suspended',
            'resume' => $state === 'active' && ($service['remaining_holds'] ?? false) !== true,
            'terminate' => in_array($state, ['terminated', 'deleted'], true),
            default => false,
        };
    }

    private function scheduleRetry(?string $errorCode = null): void
    {
        try {
            $state = $this->states->get($this->serviceId);
            $attempts = min(30, max(0, (int) ($state['poll_attempts'] ?? 0)) + 1);
            $this->states->save($this->serviceId, [
                'poll_attempts' => $attempts,
                'next_poll_at' => gmdate('Y-m-d H:i:s', time() + self::RECONCILIATION_INTERVAL_SECONDS),
                'last_request_id' => $this->api->lastRequestId(),
            ] + ($errorCode === null ? [] : [
                'last_error_code' => $errorCode,
                'last_error_message' => $errorCode,
            ]));
        } catch (\Throwable) {
        }
    }

    private function scheduleCreateRetry(?string $errorCode = null): void
    {
        try {
            $state = $this->states->get($this->serviceId);
            $attempts = min(30, max(0, (int) ($state['poll_attempts'] ?? 0)) + 1);
            $this->states->save($this->serviceId, [
                'poll_attempts' => $attempts,
                'next_poll_at' => gmdate('Y-m-d H:i:s', time() + self::CREATE_POLL_SECONDS),
                'last_request_id' => $this->api->lastRequestId(),
            ] + ($errorCode === null ? [] : [
                'last_error_code' => $errorCode,
                'last_error_message' => $errorCode,
            ]));
        } catch (\Throwable) {
        }
    }

    private function pending(string $state, ?string $publicError, ?string $requestId): string
    {
        $state = Sanitizer::text($state, 40);
        $message = $publicError === null
            ? sprintf('The Lumio service is still processing (%s); WHMCS will keep its current status and cron will continue polling', $state)
            : sprintf('The Lumio service requires attention (%s, %s); WHMCS will keep its current status', $state, $publicError);
        return $this->withRequestId($message, $requestId);
    }

    /** @param array<string, mixed> $operation */
    private function operationPublicError(array $operation): ?string
    {
        $result = is_array($operation['result'] ?? null) ? $operation['result'] : [];
        $service = is_array($result['service'] ?? null) ? $result['service'] : [];
        $publicError = $service['public_error'] ?? null;
        if (is_string($publicError) && trim($publicError) !== '') {
            return Sanitizer::errorCode($publicError);
        }
        $status = strtolower(trim((string) ($operation['status'] ?? '')));
        return in_array($status, ['needs_attention', 'failed'], true) ? 'ACTION_FAILED' : null;
    }

    private function requiredOperationId(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';
        if (preg_match('/^op_[A-Za-z0-9_-]{32}$/D', $value) !== 1) {
            throw new TransportException('The Lumio API response is missing a valid operation_id', $this->api->lastRequestId());
        }
        return $value;
    }

    private function recordError(string $code, ?string $requestId, string $message): void
    {
        try {
            $this->states->save($this->serviceId, [
                'last_error_code' => Sanitizer::errorCode($code),
                'last_error_message' => Sanitizer::text($message, 255),
                'last_request_id' => $requestId,
            ]);
            $this->properties->save([
                self::PROPERTY_LAST_ERROR => Sanitizer::errorCode($code),
                self::PROPERTY_LAST_REQUEST_ID => (string) $requestId,
            ]);
        } catch (\Throwable) {
        }
    }

    private function humanApiError(ApiException $exception): string
    {
        $message = match ($exception->errorCode) {
            'OUT_OF_STOCK' => 'Lumio inventory is currently unavailable; the wallet was not charged and the purchase can be retried after restocking',
            'WALLET_INSUFFICIENT' => 'The Lumio wallet balance is insufficient; add funds and retry',
            'WALLET_RESTRICTED' => 'The Lumio wallet is currently restricted from spending',
            'PRICE_CHANGED' => 'The Lumio cost exceeds the configured WHMCS product cap; review the price and update the cap before retrying',
            'PRODUCT_NOT_FOUND' => 'The Lumio product does not exist, is unavailable, or cannot be purchased',
            'INVALID_SELECTION' => 'The Lumio product cycle, product options, or add-ons are invalid',
            'AUTH_INVALID', 'KEY_REVOKED' => 'The Lumio API Key is invalid or has been revoked',
            'ACCOUNT_RESTRICTED' => 'The Lumio customer account is not currently allowed to use the integration API',
            'SCOPE_DENIED' => 'The Lumio API Key is missing the permission required for this operation',
            'RATE_LIMITED' => 'Lumio API requests are being rate limited; cron will retry later',
            'SERVICE_STATE_CONFLICT' => 'The current Lumio service state does not allow this operation',
            'SERVICE_PERIOD_CHANGED' => 'The Lumio service period has changed; refresh the quote and retry',
            default => 'Lumio API request failed (' . Sanitizer::errorCode($exception->errorCode) . ')',
        };
        return $this->withRequestId($message, $exception->requestId);
    }

    private function errorSummary(ApiException $exception): string
    {
        return Sanitizer::errorCode($exception->errorCode) . ': ' . Sanitizer::text($exception->getMessage(), 180);
    }

    private function withRequestId(string $message, ?string $requestId): string
    {
        $requestId = $requestId === null ? '' : Sanitizer::text($requestId, 128);
        return $requestId === '' ? $message : $message . '；Request-Id: ' . $requestId;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}
