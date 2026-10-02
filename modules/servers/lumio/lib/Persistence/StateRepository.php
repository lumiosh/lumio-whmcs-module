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

    private const JSON_FIELDS = ['purchase_payload', 'pending_payload', 'interrupted_operation'];

    private const WRITABLE_FIELDS = [
        'purchase_started_at',
        'pending_started_at',
        'interrupted_operation',
        'renewal_ledger_initialized_at',
        'renewal_legacy_invoice_cutoff',
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
            } else {
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
            }
            foreach (['pending_started_at', 'purchase_started_at', 'renewal_ledger_initialized_at'] as $column) {
                if (! $schema->hasColumn(self::TABLE, $column)) {
                    $schema->table(self::TABLE, static fn (Blueprint $table) => $table->dateTime($column)->nullable());
                }
            }
            if (! $schema->hasColumn(self::TABLE, 'interrupted_operation')) {
                $schema->table(self::TABLE, static fn (Blueprint $table) => $table->text('interrupted_operation')->nullable());
            }
            if (! $schema->hasColumn(self::TABLE, 'renewal_legacy_invoice_cutoff')) {
                $schema->table(self::TABLE, static fn (Blueprint $table) => $table->unsignedBigInteger('renewal_legacy_invoice_cutoff')->nullable());
            }
            // Freeze the upgrade boundary before any later payment callback. NULL
            // also makes an interrupted migration resumable. New service rows use 0.
            if (Capsule::table(self::TABLE)->whereNull('renewal_legacy_invoice_cutoff')->exists()) {
                $cutoff = (int) Capsule::table('tblinvoices')->max('id');
                Capsule::table(self::TABLE)->whereNull('renewal_legacy_invoice_cutoff')->update(['renewal_legacy_invoice_cutoff' => $cutoff]);
            }
            if (! $schema->hasTable('mod_lumio_renewals')) {
                $schema->create('mod_lumio_renewals', static function (Blueprint $table): void {
                    $table->unsignedInteger('service_id');
                    $table->unsignedBigInteger('invoice_id');
                    $table->string('status', 32)->default('queued');
                    $table->text('payload')->nullable();
                    $table->string('external_reference', 190)->nullable();
                    $table->string('operation_id', 64)->nullable();
                    $table->string('last_error_code', 64)->nullable();
                    $table->dateTime('started_at')->nullable();
                    $table->dateTime('next_poll_at')->nullable();
                    $table->dateTime('completed_at')->nullable();
                    $table->dateTime('created_at');
                    $table->dateTime('updated_at');
                    $table->primary(['service_id', 'invoice_id']);
                    $table->index(['status', 'next_poll_at'], 'idx_lumio_renewal_poll');
                });
            }
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
            'renewal_legacy_invoice_cutoff' => 0,
            'delivery_state' => (string) ($encoded['delivery_state'] ?? 'pending'),
            'action_sequence' => (int) ($encoded['action_sequence'] ?? 0),
            'poll_attempts' => (int) ($encoded['poll_attempts'] ?? 0),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function renewals(int $serviceId): array
    {
        $result = [];
        foreach (Capsule::table('mod_lumio_renewals')->where('service_id', $serviceId)->orderBy('invoice_id')->get() as $row) {
            $item = (array) $row;
            $item['payload'] = $this->decodeJson($item['payload']);
            $result[] = $item;
        }
        return $result;
    }

    public function saveRenewal(int $serviceId, int $invoiceId, array $changes): void
    {
        $allowed = ['status', 'payload', 'external_reference', 'operation_id', 'last_error_code', 'started_at', 'next_poll_at', 'completed_at'];
        if ($invoiceId < 1 || array_diff(array_keys($changes), $allowed) !== []) {
            throw new \InvalidArgumentException('Invalid renewal ledger update');
        }
        if (isset($changes['payload'])) {
            $changes['payload'] = json_encode($changes['payload'], JSON_THROW_ON_ERROR);
        }
        $query = Capsule::table('mod_lumio_renewals')->where('service_id', $serviceId)->where('invoice_id', $invoiceId);
        $now = gmdate('Y-m-d H:i:s');
        if ($query->exists()) {
            $query->update($changes + ['updated_at' => $now]);
        } else {
            Capsule::table('mod_lumio_renewals')->insert($changes + [
                'service_id' => $serviceId, 'invoice_id' => $invoiceId, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function pendingRenewalServiceIds(int $limit): array
    {
        // Only the oldest unfinished invoice may advance this service's billing period.
        return Capsule::table('mod_lumio_renewals as renewal')
            ->whereIn('renewal.status', ['queued', 'retry'])
            ->where(static fn ($q) => $q->whereNull('renewal.next_poll_at')
                ->orWhere('renewal.next_poll_at', '<=', gmdate('Y-m-d H:i:s'))
                ->orWhere('renewal.started_at', '<=', gmdate('Y-m-d H:i:s', time() - \Lumio\Whmcs\RenewalProcessor::RETRY_WINDOW_SECONDS)))
            ->whereNotExists(static function ($q): void {
                $q->selectRaw('1')->from('mod_lumio_renewals as earlier')
                    ->whereColumn('earlier.service_id', 'renewal.service_id')
                    ->where('earlier.status', '!=', 'completed')
                    ->whereColumn('earlier.invoice_id', '<', 'renewal.invoice_id');
            })
            ->orderBy('renewal.next_poll_at')->orderBy('renewal.service_id')
            ->limit(max(1, min($limit, 100)))->pluck('renewal.service_id')
            ->map(static fn ($id): int => (int) $id)->all();
    }

    public function pendingLifecycle(int $limit): array
    {
        $rows = Capsule::table(self::TABLE)
            ->select(['service_id', 'pending_action'])
            ->whereIn('pending_action', ['renew', 'suspend', 'resume', 'terminate', 'suspend_rollback'])
            ->where(static function ($query): void {
                $query->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', gmdate('Y-m-d H:i:s'))
                    ->orWhere('pending_started_at', '<=', gmdate('Y-m-d H:i:s', time() - \Lumio\Whmcs\ModuleWorkflow::PENDING_TIMEOUT_SECONDS));
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
            'purchase_started_at' => null,
            'pending_started_at' => null,
            'interrupted_operation' => null,
            'renewal_ledger_initialized_at' => null, 'renewal_legacy_invoice_cutoff' => 0,
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
