<?php

declare(strict_types=1);

namespace Lumio\Whmcs;

use Lumio\Whmcs\Persistence\StateRepository;
use Lumio\Whmcs\Support\Sanitizer;

final class ModuleInspector
{
    /** @return array<string, string> */
    public function state(int $serviceId): array
    {
        if ($serviceId < 1) {
            return $this->emptyState('INVALID_SERVICE_ID');
        }
        try {
            $repository = new StateRepository();
            $repository->ensureSchema();
            $state = $repository->get($serviceId);
            return [
                'service_number' => Sanitizer::text((string) ($state['lumio_service_number'] ?? ''), 64),
                'service_id' => $this->positiveId($state['lumio_service_id'] ?? null),
                'delivery_state' => Sanitizer::text((string) ($state['delivery_state'] ?? 'pending'), 32),
                'credentials_delivered' => ($state['activation_reported_at'] ?? null) === null ? '' : '1',
                'operation_id' => Sanitizer::text((string) ($state['pending_operation_id'] ?? $state['purchase_operation_id'] ?? ''), 64),
                'external_reference' => Sanitizer::text((string) ($state['pending_external_reference'] ?? $state['purchase_external_reference'] ?? ''), 190),
                'pending_action' => Sanitizer::text((string) ($state['pending_action'] ?? ''), 32),
                'last_error' => $this->errorCodeOrBlank($state['last_error_code'] ?? null),
                'last_request_id' => Sanitizer::text((string) ($state['last_request_id'] ?? ''), 128),
            ];
        } catch (\Throwable) {
            return $this->emptyState('MODULE_STATE_UNAVAILABLE');
        }
    }

    /** @return array<string, string> */
    private function emptyState(string $error): array
    {
        return [
            'service_number' => '',
            'service_id' => '',
            'delivery_state' => 'unknown',
            'credentials_delivered' => '',
            'operation_id' => '',
            'external_reference' => '',
            'pending_action' => '',
            'last_error' => Sanitizer::errorCode($error),
            'last_request_id' => '',
        ];
    }

    private function positiveId(mixed $value): string
    {
        $value = (int) $value;
        return $value > 0 ? (string) $value : '';
    }

    private function errorCodeOrBlank(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';
        return $value === '' ? '' : Sanitizer::errorCode($value);
    }
}
