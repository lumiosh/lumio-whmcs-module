<?php

declare(strict_types=1);

namespace Lumio\Whmcs\Persistence;

use Illuminate\Database\Schema\Blueprint;
use Lumio\Whmcs\Contract\StateRepositoryInterface;
use RuntimeException;
use WHMCS\Database\Capsule;

final class StateRepository implements StateRepositoryInterface
{
    public const TABLE = 'mod_lumio_service_state';

    private const JSON_FIELDS = ['purchase_payload', 'pending_payload'];

    private const WRITABLE_FIELDS = [
        'purchase_external_reference',
        'purchase_operation_id',
        'purchase_payload',
        'lumio_service_id',
        'lumio_service_number',
        'delivery_state',
        'activation_reported_at',
        'activation_acknowledged_at',
        'provisioning_invoice_id',
        'last_renewal_invoice_id',
        'pending_invoice_id',
        'pending_action',
        'pending_external_reference',
        'pending_operation_id',
        'pending_payload',
        'action_sequence',
        'last_completed_action',
        'last_completed_at',
        'last_request_id',
        'last_error_code',
        'last_error_message',
        'poll_attempts',
        'next_poll_at',
    ];

    public function ensureSchema(): void
    {
        $this->withNamedLock($this->schemaLockName(), 10, static function (): void {
            $schema = Capsule::schema();
            if ($schema->hasTable(self::TABLE)) {
                if (! $schema->hasColumn(self::TABLE, 'pending_invoice_id')) {
                    $schema->table(self::TABLE, static function (Blueprint $table): void {
                        $table->unsignedBigInteger('pending_invoice_id')->nullable()->after('last_renewal_invoice_id');
                    });
                }
                if (! $schema->hasColumn(self::TABLE, 'activation_reported_at')) {
                    $schema->table(self::TABLE, static function (Blueprint $table): void {
                        $table->dateTime('activation_reported_at')->nullable()->after('delivery_state');
                    });
                }
                if (! $schema->hasColumn(self::TABLE, 'activation_acknowledged_at')) {
                    $schema->table(self::TABLE, static function (Blueprint $table): void {
                        $table->dateTime('activation_acknowledged_at')->nullable()->after('activation_reported_at');
                    });
                }
                if (! $schema->hasColumn(self::TABLE, 'last_completed_action')) {
                    $schema->table(self::TABLE, static function (Blueprint $table): void {
                        $table->string('last_completed_action', 32)->nullable()->after('action_sequence');
                    });
                }
                if (! $schema->hasColumn(self::TABLE, 'last_completed_at')) {
                    $schema->table(self::TABLE, static function (Blueprint $table): void {
                        $table->dateTime('last_completed_at')->nullable()->after('last_completed_action');
                    });
                }
                return;
            }
            $schema->create(self::TABLE, static function (Blueprint $table): void {
                $table->unsignedInteger('service_id')->primary();
                $table->string('purchase_external_reference', 190)->nullable();
                $table->string('purchase_operation_id', 64)->nullable();
                $table->text('purchase_payload')->nullable();
                $table->unsignedBigInteger('lumio_service_id')->nullable();
                $table->string('lumio_service_number', 64)->nullable();
                $table->string('delivery_state', 32)->default('pending');
                $table->dateTime('activation_reported_at')->nullable();
                $table->dateTime('activation_acknowledged_at')->nullable();
                $table->unsignedBigInteger('provisioning_invoice_id')->nullable();
                $table->unsignedBigInteger('last_renewal_invoice_id')->nullable();
                $table->unsignedBigInteger('pending_invoice_id')->nullable();
                $table->string('pending_action', 32)->nullable();
                $table->string('pending_external_reference', 190)->nullable();
                $table->string('pending_operation_id', 64)->nullable();
                $table->text('pending_payload')->nullable();
                $table->unsignedInteger('action_sequence')->default(0);
                $table->string('last_completed_action', 32)->nullable();
                $table->dateTime('last_completed_at')->nullable();
                $table->string('last_request_id', 128)->nullable();
                $table->string('last_error_code', 64)->nullable();
                $table->string('last_error_message', 255)->nullable();
                $table->unsignedInteger('poll_attempts')->default(0);
                $table->dateTime('next_poll_at')->nullable();
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
                $table->index(['pending_action', 'next_poll_at'], 'idx_lumio_pending_poll');
            });
        });
    }

    public function get(int $serviceId): array
    {
        $row = Capsule::table(self::TABLE)->where('service_id', $serviceId)->first();
        if ($row === null) {
            return $this->defaults($serviceId);
        }
        $state = (array) $row;
        foreach (self::JSON_FIELDS as $field) {
            $state[$field] = $this->decodeJson($state[$field] ?? null);
        }
        return $state;
    }

    public function save(int $serviceId, array $changes): void
    {
        $unknown = array_diff(array_keys($changes), self::WRITABLE_FIELDS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('An unknown Lumio module state field cannot be written');
        }

        $now = gmdate('Y-m-d H:i:s');
        $encoded = [];
        foreach ($changes as $field => $value) {
            if (in_array($field, self::JSON_FIELDS, true) && $value !== null) {
                $encoded[$field] = json_encode(
                    $value,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                );
            } else {
                $encoded[$field] = $value;
            }
        }

        $exists = Capsule::table(self::TABLE)->where('service_id', $serviceId)->exists();
        if ($exists) {
            Capsule::table(self::TABLE)->where('service_id', $serviceId)->update($encoded + ['updated_at' => $now]);
            return;
        }
        Capsule::table(self::TABLE)->insert($encoded + [
            'service_id' => $serviceId,
            'delivery_state' => (string) ($encoded['delivery_state'] ?? 'pending'),
            'action_sequence' => (int) ($encoded['action_sequence'] ?? 0),
            'poll_attempts' => (int) ($encoded['poll_attempts'] ?? 0),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function pendingLifecycle(int $limit): array
    {
        $rows = Capsule::table(self::TABLE)
            ->select(['service_id', 'pending_action'])
            ->whereIn('pending_action', ['renew', 'suspend', 'resume', 'terminate', 'suspend_rollback'])
            ->where(static function ($query): void {
                $query->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', gmdate('Y-m-d H:i:s'));
            })
            ->orderBy('next_poll_at')
            ->orderBy('service_id')
            ->limit(max(1, min($limit, 100)))
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'service_id' => (int) $row->service_id,
                'pending_action' => (string) $row->pending_action,
            ];
        }
        return $result;
    }

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

    /** @return null|array<string, mixed> */
    private function decodeJson(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            throw new RuntimeException('The Lumio module state JSON type is invalid');
        }
        try {
            $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('The Lumio module state JSON is corrupted', 0, $exception);
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('The Lumio module state JSON structure is invalid');
        }
        return $decoded;
    }

    private function withNamedLock(string $name, int $timeout, callable $callback): mixed
    {
        $rows = Capsule::select('SELECT GET_LOCK(?, ?) AS acquired', [$name, $timeout]);
        if ((int) ($rows[0]->acquired ?? 0) !== 1) {
            throw new RuntimeException('Unable to acquire the Lumio module database initialization lock');
        }
        try {
            return $callback();
        } finally {
            Capsule::select('SELECT RELEASE_LOCK(?) AS released', [$name]);
        }
    }

    private function schemaLockName(): string
    {
        $rows = Capsule::select('SELECT DATABASE() AS database_name');
        $database = trim((string) ($rows[0]->database_name ?? ''));
        if ($database === '') {
            throw new RuntimeException('Unable to identify the current WHMCS database; the Lumio state table cannot be initialized safely');
        }
        return 'lumio-' . substr(hash('sha256', $database), 0, 12) . '-schema-v1';
    }
}
