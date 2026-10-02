<?php

declare(strict_types=1);

namespace Lumio\Whmcs;

use Lumio\Whmcs\Support\UtcTime;

use Lumio\Whmcs\Contract\{ApiClientInterface, RuntimeInterface, StateRepositoryInterface, ServicePropertiesInterface};
use Lumio\Whmcs\Exception\{ApiException, ConfigurationException, TransportException};

/** A paid invoice owns its request for its entire lifetime, including response loss. */
final class RenewalProcessor
{
    public const RETRY_WINDOW_SECONDS = 3600;
    private const BATCH_SIZE = 10;

    public function __construct(
        private readonly int $serviceId,
        private readonly Configuration $configuration,
        private readonly ApiClientInterface $api,
        private readonly StateRepositoryInterface $states,
        private readonly RuntimeInterface $runtime,
        private readonly ServicePropertiesInterface $properties,
    ) {}

    public function importLegacy(): void
    {
        $state = $this->states->get($this->serviceId);
        $rows = $this->rowsByInvoice();
        $pendingInvoice = in_array($state['pending_action'] ?? null, ['renew', 'renew_review'], true) ? (int) ($state['pending_invoice_id'] ?? 0) : 0;
        if ($pendingInvoice > 0 && ! isset($rows[$pendingInvoice])) {
            $this->states->saveRenewal($this->serviceId, $pendingInvoice, [
                'status' => ($state['pending_action'] ?? null) === 'renew_review' ? 'needs_attention' : 'retry', 'payload' => $state['pending_payload'] ?? null,
                'external_reference' => $state['pending_external_reference'] ?? null,
                'started_at' => $state['pending_started_at'] ?? gmdate('Y-m-d H:i:s'),
                'next_poll_at' => $state['next_poll_at'] ?? null,
            ]);
        }
        if (($state['renewal_ledger_initialized_at'] ?? null) === null) {
            // Old releases recorded only a high-water mark. Do not charge historical
            // paid invoices again on upgrade; their individual outcomes need an audit.
            $checkpoint = (int) ($state['last_renewal_invoice_id'] ?? 0);
            if ($checkpoint > 0) {
                foreach ($this->runtime->paidHostingInvoiceIds($this->serviceId) as $invoiceId) {
                    if ($invoiceId <= $checkpoint && $invoiceId !== $pendingInvoice && ! isset($rows[$invoiceId])) {
                        $this->states->saveRenewal($this->serviceId, $invoiceId, [
                            'status' => 'completed', 'last_error_code' => 'LEGACY_CHECKPOINT',
                        ]);
                    }
                }
            }
            $this->states->save($this->serviceId, ['renewal_ledger_initialized_at' => gmdate('Y-m-d H:i:s')]);
        }
        if (in_array($state['pending_action'] ?? null, ['renew', 'renew_review'], true)) {
            if ($pendingInvoice < 1) {
                throw new ConfigurationException('The legacy pending renewal has no invoice ID; restore its invoice reference before retrying');
            }
            $this->states->save($this->serviceId, [
                'pending_action' => null, 'pending_invoice_id' => null, 'pending_payload' => null,
                'pending_operation_id' => null, 'pending_external_reference' => null,
                'pending_started_at' => null, 'next_poll_at' => null, 'poll_attempts' => 0,
            ]);
        }
    }

    public function run(int $lumioServiceId, bool $pendingOnly, bool $manualRetry = false): string
    {
        $this->importLegacy();
        $state = $this->states->get($this->serviceId);
        if (! $pendingOnly) {
            $paid = $this->runtime->paidHostingInvoiceIds($this->serviceId);
            if ($paid === []) {
                return 'No paid WHMCS renewal invoice was found for this service; Lumio will not charge the wallet';
            }
            $provisioning = (int) ($state['provisioning_invoice_id'] ?? $this->properties->get(ModuleWorkflow::PROPERTY_PROVISIONING_INVOICE_ID) ?? 0);
            if ($provisioning < 1) {
                throw new ConfigurationException('The original provisioning invoice is missing; restore it before processing renewals');
            }
            $rows = $this->rowsByInvoice();
            foreach ($paid as $invoiceId) {
                if ($invoiceId <= $provisioning || isset($rows[$invoiceId])) {
                    continue;
                }
                // Persist before quoting: even a failed quote must not lose a paid invoice.
                $this->states->saveRenewal($this->serviceId, $invoiceId, ['status' => $invoiceId <= (int) ($state['renewal_legacy_invoice_cutoff'] ?? 0) ? 'needs_attention' : 'queued',
                    'last_error_code' => $invoiceId <= (int) ($state['renewal_legacy_invoice_cutoff'] ?? 0) ? 'LEGACY_INVOICE_REVIEW' : null]);
            }
            if ($this->rowsByInvoice() === [] && max($paid) <= $provisioning) {
                return 'Only the original provisioning invoice exists; no new paid renewal invoice was found';
            }
        }
        $processed = 0;
        foreach ($this->states->renewals($this->serviceId) as $row) {
            if ($row['status'] === 'completed') {
                continue;
            }
            $invoiceId = (int) $row['invoice_id'];
            if (! $manualRetry && $row['status'] === 'needs_attention') {
                return sprintf('Renewal invoice #%d requires an explicit retry; later invoices remain queued', $invoiceId);
            }
            if ($processed >= self::BATCH_SIZE) {
                return 'More paid renewal invoices are queued; cron will continue';
            }
            $started = UtcTime::timestamp((string) ($row['started_at'] ?? ''));
            if ($started !== false && $started + self::RETRY_WINDOW_SECONDS <= time()) {
                $this->states->saveRenewal($this->serviceId, $invoiceId, ['status' => 'needs_attention', 'next_poll_at' => null, 'last_error_code' => 'RENEWAL_RETRY_EXPIRED']);
                $this->states->save($this->serviceId, ['last_error_code' => 'RENEWAL_RETRY_EXPIRED', 'last_error_message' => sprintf('Renewal invoice #%d reached its deadline; use Retry Paid Renewals', $invoiceId)]);
                if (! $manualRetry) {
                    return sprintf('Renewal invoice #%d reached its retry deadline; retry it explicitly using the saved request', $invoiceId);
                }
                $started = false;
            }
            if ($pendingOnly && UtcTime::timestamp((string) ($row['next_poll_at'] ?? '')) > time()) {
                return 'The paid renewal is scheduled for a later retry';
            }
            if ($manualRetry && $row['status'] === 'needs_attention') {
                $started = false;
            }
            $this->states->saveRenewal($this->serviceId, $invoiceId, [
                'status' => 'retry', 'started_at' => $started === false ? gmdate('Y-m-d H:i:s') : gmdate('Y-m-d H:i:s', $started),
                'next_poll_at' => null,
            ]);
            $reference = $row['external_reference'] ?? $this->configuration->externalReference($this->serviceId, 'renew-invoice', $invoiceId);
            $payload = $row['payload'] ?? null;
            $completed = false;
            try {
                $this->runtime->assertProductCompatible($this->serviceId);
                $this->configuration->assertTerminationPolicyAccepted();
                if ($lumioServiceId < 1) throw new ConfigurationException('This WHMCS service is not linked to a Lumio Service ID');
                if (! is_array($payload)) {
                    $payload = $this->quote($lumioServiceId, $reference);
                    $this->states->saveRenewal($this->serviceId, $invoiceId, ['payload' => $payload, 'external_reference' => $reference]);
                }
                $result = $this->api->renew($lumioServiceId, $payload, $this->configuration->idempotencyKey($reference));
                $operationId = $result['operation_id'] ?? '';
                if (! is_string($operationId) || preg_match('/^op_[A-Za-z0-9_-]{32}$/D', $operationId) !== 1) {
                    throw new TransportException('The Lumio renewal response is missing a valid operation_id', $this->api->lastRequestId());
                }
                $this->states->saveRenewal($this->serviceId, $invoiceId, [
                    'status' => 'completed', 'operation_id' => $operationId, 'completed_at' => gmdate('Y-m-d H:i:s'),
                    'next_poll_at' => null, 'last_error_code' => null,
                ]);
                $completed = true;
                $this->states->save($this->serviceId, [
                    'last_renewal_invoice_id' => $invoiceId, 'last_request_id' => $this->api->lastRequestId(),
                    'last_error_code' => null, 'last_error_message' => null,
                ]);
                $this->properties->save([
                    ModuleWorkflow::PROPERTY_OPERATION_ID => $operationId,
                    ModuleWorkflow::PROPERTY_LAST_RENEWAL_INVOICE_ID => $invoiceId,
                    ModuleWorkflow::PROPERTY_LAST_ERROR => '',
                ]);
                ++$processed;
            } catch (\Throwable $exception) {
                // Metadata failure must not reopen a durably completed charge.
                if ($completed) {
                    throw $exception;
                }
                $definitive = $exception instanceof ConfigurationException
                    || ($exception instanceof ApiException && self::definitiveRejection($exception));
                $changes = [
                    'status' => $definitive ? 'needs_attention' : 'retry',
                    'next_poll_at' => $definitive ? null : gmdate('Y-m-d H:i:s', time() + max(300, $exception instanceof ApiException ? min(3600, $exception->retryAfter ?? 0) : 0)),
                    'last_error_code' => $exception instanceof ApiException ? $exception->errorCode : ($definitive ? 'CONFIGURATION_ERROR' : 'TRANSPORT_ERROR'),
                ];
                // These business rejections roll back the backend renewal transaction.
                // Unknown outcomes and idempotency conflicts MUST keep their exact payload.
                if ($exception instanceof ApiException && in_array($exception->errorCode, ['PRICE_CHANGED', 'SERVICE_PERIOD_CHANGED', 'WALLET_INSUFFICIENT', 'WALLET_RESTRICTED'], true)) {
                    $changes['payload'] = null;
                }
                $this->states->saveRenewal($this->serviceId, $invoiceId, $changes);
                throw $exception;
            }
        }
        return 'success';
    }

    public static function definitiveRejection(ApiException $exception): bool
    {
        return $exception->httpStatus >= 400 && $exception->httpStatus < 500
            && ! in_array($exception->httpStatus, [408, 425, 429], true);
    }

    private function quote(int $serviceId, string $reference): array
    {
        $quote = $this->api->renewalQuote($serviceId);
        if (strtolower(trim((string) ($quote['billing_cycle'] ?? ''))) !== $this->configuration->billingCycle()) {
            throw new ConfigurationException('The WHMCS billing cycle does not match the Lumio service billing cycle');
        }
        $cap = $this->configuration->costCapCents();
        if ((int) ($quote['total_cents'] ?? -1) < 0 || (int) $quote['total_cents'] > $cap) {
            throw new ApiException(409, 'PRICE_CHANGED', $this->api->lastRequestId(), null, 'The renewal amount exceeds the configured cost cap');
        }
        $due = trim((string) ($quote['current_next_due_at'] ?? ''));
        if ($due === '') {
            throw new TransportException('The Lumio renewal quote is missing the current due date', $this->api->lastRequestId());
        }
        return ['external_reference' => $reference, 'expected_next_due_at' => $due, 'expected_total_cents' => $cap];
    }

    private function rowsByInvoice(): array
    {
        $result = [];
        foreach ($this->states->renewals($this->serviceId) as $row) {
            $result[(int) $row['invoice_id']] = $row;
        }
        return $result;
    }
}
