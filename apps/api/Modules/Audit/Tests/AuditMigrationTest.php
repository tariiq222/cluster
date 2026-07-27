<?php

declare(strict_types=1);

namespace Modules\Audit\Tests;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AuditMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = [
        'audit_events',
        'audit_export_jobs',
        'audit_integrity_checkpoints',
        'audit_idempotency_keys',
    ];

    private Closure $migrationUp;

    private Closure $migrationDown;

    protected function setUp(): void
    {
        parent::setUp();

        $migration = require dirname(__DIR__).'/Infrastructure/Persistence/Migrations/CreateAuditTables.php';
        if (! is_object($migration)
            || ! method_exists($migration, 'up')
            || ! method_exists($migration, 'down')) {
            $this->fail('CreateAuditTables must return a migration with up() and down().');
        }
        $this->migrationUp = $migration->up(...);
        $this->migrationDown = $migration->down(...);
        ($this->migrationDown)();
        ($this->migrationUp)();
    }

    public function test_sqlite_schema_has_exact_columns_nullability_and_no_foreign_keys(): void
    {
        $expectedColumns = [
            'audit_events' => [
                'id', 'request_hash', 'stream_key', 'stream_sequence', 'source_module',
                'action', 'event_type', 'actor_type', 'actor_id', 'original_actor_id',
                'subject_type', 'subject_id', 'correlation_id', 'outcome', 'classification',
                'context', 'context_schema_version', 'redaction_policy_version', 'occurred_at',
                'recorded_at', 'retention_until', 'previous_hash', 'event_hash',
                'integrity_key_version',
            ],
            'audit_export_jobs' => [
                'id', 'principal_id', 'facility_id', 'query', 'query_hash', 'reason_redacted',
                'format', 'snapshot_recorded_at', 'status', 'event_count', 'lock_version',
                'expires_at', 'created_at', 'updated_at',
            ],
            'audit_integrity_checkpoints' => [
                'id', 'stream_key', 'kind', 'first_sequence', 'last_sequence', 'event_count',
                'terminal_event_hash', 'previous_checkpoint_hash', 'checkpoint_hash',
                'integrity_key_version', 'status', 'actor_id', 'correlation_id', 'details',
                'verified_at', 'created_at',
            ],
            'audit_idempotency_keys' => [
                'id', 'principal_id', 'operation', 'key_hash', 'request_hash',
                'response_status', 'response_payload', 'resource_id', 'created_at', 'updated_at',
            ],
        ];
        $expectedNullable = [
            'audit_events' => ['actor_id', 'original_actor_id', 'previous_hash', 'subject_id'],
            'audit_export_jobs' => ['created_at', 'facility_id', 'updated_at'],
            'audit_integrity_checkpoints' => ['actor_id', 'previous_checkpoint_hash'],
            'audit_idempotency_keys' => ['created_at', 'updated_at'],
        ];
        $expectedTypes = [
            'audit_events' => [
                'id' => 'varchar',
                'request_hash' => 'varchar',
                'stream_key' => 'varchar',
                'stream_sequence' => 'integer',
                'source_module' => 'varchar',
                'action' => 'varchar',
                'event_type' => 'varchar',
                'actor_type' => 'varchar',
                'actor_id' => 'varchar',
                'original_actor_id' => 'varchar',
                'subject_type' => 'varchar',
                'subject_id' => 'varchar',
                'correlation_id' => 'varchar',
                'outcome' => 'varchar',
                'classification' => 'varchar',
                'context' => 'text',
                'context_schema_version' => 'integer',
                'redaction_policy_version' => 'varchar',
                'occurred_at' => 'datetime',
                'recorded_at' => 'datetime',
                'retention_until' => 'datetime',
                'previous_hash' => 'varchar',
                'event_hash' => 'varchar',
                'integrity_key_version' => 'varchar',
            ],
            'audit_export_jobs' => [
                'id' => 'varchar',
                'principal_id' => 'varchar',
                'facility_id' => 'varchar',
                'query' => 'text',
                'query_hash' => 'varchar',
                'reason_redacted' => 'varchar',
                'format' => 'varchar',
                'snapshot_recorded_at' => 'datetime',
                'status' => 'varchar',
                'event_count' => 'integer',
                'lock_version' => 'integer',
                'expires_at' => 'datetime',
                'created_at' => 'datetime',
                'updated_at' => 'datetime',
            ],
            'audit_integrity_checkpoints' => [
                'id' => 'varchar',
                'stream_key' => 'varchar',
                'kind' => 'varchar',
                'first_sequence' => 'integer',
                'last_sequence' => 'integer',
                'event_count' => 'integer',
                'terminal_event_hash' => 'varchar',
                'previous_checkpoint_hash' => 'varchar',
                'checkpoint_hash' => 'varchar',
                'integrity_key_version' => 'varchar',
                'status' => 'varchar',
                'actor_id' => 'varchar',
                'correlation_id' => 'varchar',
                'details' => 'text',
                'verified_at' => 'datetime',
                'created_at' => 'datetime',
            ],
            'audit_idempotency_keys' => [
                'id' => 'varchar',
                'principal_id' => 'varchar',
                'operation' => 'varchar',
                'key_hash' => 'varchar',
                'request_hash' => 'varchar',
                'response_status' => 'integer',
                'response_payload' => 'text',
                'resource_id' => 'varchar',
                'created_at' => 'datetime',
                'updated_at' => 'datetime',
            ],
        ];

        foreach ($expectedColumns as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertSame($columns, Schema::getColumnListing($table));
            $this->assertSame($expectedNullable[$table], $this->nullableColumns($table));
            $this->assertSame($expectedTypes[$table], $this->columnTypes($table));
            $this->assertSame([], Schema::getForeignKeys($table));
            $this->assertSqlitePrimaryKey($table, 'id');
        }

        $this->assertFalse(Schema::hasTable('audit_retention_policies'));
    }

    public function test_sqlite_schema_has_every_named_index_and_unique_constraint(): void
    {
        $this->assertIndex('audit_events', 'audit_events_stream_sequence_unique', ['stream_key', 'stream_sequence'], true);
        $this->assertIndex('audit_events', 'audit_events_event_hash_unique', ['event_hash'], true);
        $this->assertIndex('audit_events', 'audit_events_recorded_index', ['recorded_at', 'id'], false);
        $this->assertIndex('audit_events', 'audit_events_actor_recorded_index', ['actor_id', 'recorded_at', 'id'], false);
        $this->assertIndex('audit_events', 'audit_events_subject_recorded_index', ['subject_type', 'subject_id', 'recorded_at', 'id'], false);
        $this->assertIndex('audit_events', 'audit_events_correlation_recorded_index', ['correlation_id', 'recorded_at', 'id'], false);
        $this->assertIndex('audit_events', 'audit_events_source_action_recorded_index', ['source_module', 'action', 'recorded_at', 'id'], false);
        $this->assertIndex('audit_events', 'audit_events_classification_recorded_index', ['classification', 'recorded_at', 'id'], false);
        $this->assertIndex('audit_events', 'audit_events_retention_stream_sequence_index', ['retention_until', 'stream_key', 'stream_sequence'], false);

        $this->assertIndex('audit_export_jobs', 'audit_export_jobs_principal_query_snapshot_index', ['principal_id', 'query_hash', 'snapshot_recorded_at'], false);
        $this->assertIndex('audit_export_jobs', 'audit_export_jobs_principal_status_created_index', ['principal_id', 'status', 'created_at'], false);
        $this->assertIndex('audit_export_jobs', 'audit_export_jobs_expires_status_index', ['expires_at', 'status'], false);

        $this->assertIndex('audit_integrity_checkpoints', 'audit_integrity_checkpoints_stream_kind_last_status_unique', ['stream_key', 'kind', 'last_sequence', 'status'], true);
        $this->assertIndex('audit_integrity_checkpoints', 'audit_integrity_checkpoints_hash_unique', ['checkpoint_hash'], true);
        $this->assertIndex('audit_integrity_checkpoints', 'audit_integrity_checkpoints_stream_last_index', ['stream_key', 'last_sequence'], false);
        $this->assertIndex('audit_integrity_checkpoints', 'audit_integrity_checkpoints_status_verified_index', ['status', 'verified_at'], false);
        $this->assertIndex('audit_integrity_checkpoints', 'audit_integrity_checkpoints_correlation_index', ['correlation_id'], false);

        $this->assertIndex('audit_idempotency_keys', 'audit_idempotency_keys_principal_operation_key_unique', ['principal_id', 'operation', 'key_hash'], true);
    }

    public function test_sqlite_checks_reject_every_invalid_event_export_and_checkpoint_value(): void
    {
        foreach ([
            ['actor_type' => 'robot'],
            ['outcome' => 'ok'],
            ['classification' => 'secret'],
            ['stream_sequence' => 0],
            ['retention_until' => '2026-07-27 10:00:00.000'],
            ['request_hash' => str_repeat('A', 64)],
            ['previous_hash' => str_repeat('g', 64)],
            ['event_hash' => str_repeat('z', 64)],
        ] as $changes) {
            $this->assertQueryRejected(fn () => DB::table('audit_events')->insert($this->eventRow($changes)));
        }

        $this->assertQueryRejected(fn () => DB::table('audit_export_jobs')->insert(
            $this->exportRow(['format' => 'pdf']),
        ));
        $this->assertQueryRejected(fn () => DB::table('audit_export_jobs')->insert(
            $this->exportRow(['status' => 'pending']),
        ));

        $this->assertQueryRejected(fn () => DB::table('audit_integrity_checkpoints')->insert(
            $this->checkpointRow(['kind' => 'manual']),
        ));
        $this->assertQueryRejected(fn () => DB::table('audit_integrity_checkpoints')->insert(
            $this->checkpointRow(['status' => 'unknown']),
        ));

        DB::table('audit_events')->insert($this->eventRow());
        DB::table('audit_export_jobs')->insert($this->exportRow());
        DB::table('audit_integrity_checkpoints')->insert($this->checkpointRow());

        $this->assertDatabaseCount('audit_events', 1);
        $this->assertDatabaseCount('audit_export_jobs', 1);
        $this->assertDatabaseCount('audit_integrity_checkpoints', 1);
    }

    public function test_sqlite_unique_constraints_match_event_checkpoint_and_idempotency_semantics(): void
    {
        $event = $this->eventRow();
        DB::table('audit_events')->insert($event);
        $this->assertQueryRejected(fn () => DB::table('audit_events')->insert($this->eventRow([
            'stream_sequence' => 2,
            'event_hash' => str_repeat('b', 64),
        ])));
        $this->assertQueryRejected(fn () => DB::table('audit_events')->insert($this->eventRow([
            'id' => '018f6f7d-0c00-7000-8000-000000000202',
            'event_hash' => str_repeat('b', 64),
        ])));
        $this->assertQueryRejected(fn () => DB::table('audit_events')->insert($this->eventRow([
            'id' => '018f6f7d-0c00-7000-8000-000000000203',
            'stream_sequence' => 2,
        ])));

        DB::table('audit_export_jobs')->insert($this->exportRow());
        DB::table('audit_export_jobs')->insert($this->exportRow([
            'id' => '018f6f7d-0c00-7000-8000-000000000204',
        ]));
        $this->assertDatabaseCount('audit_export_jobs', 2);

        DB::table('audit_integrity_checkpoints')->insert($this->checkpointRow());
        $this->assertQueryRejected(fn () => DB::table('audit_integrity_checkpoints')->insert(
            $this->checkpointRow([
                'id' => '018f6f7d-0c00-7000-8000-000000000205',
                'checkpoint_hash' => str_repeat('d', 64),
            ]),
        ));
        $this->assertQueryRejected(fn () => DB::table('audit_integrity_checkpoints')->insert(
            $this->checkpointRow([
                'id' => '018f6f7d-0c00-7000-8000-000000000206',
                'last_sequence' => 2,
            ]),
        ));

        DB::table('audit_idempotency_keys')->insert($this->idempotencyRow());
        $this->assertQueryRejected(fn () => DB::table('audit_idempotency_keys')->insert(
            $this->idempotencyRow(['id' => '018f6f7d-0c00-7000-8000-000000000207']),
        ));
    }

    public function test_sqlite_event_and_checkpoint_guards_enforce_required_immutability(): void
    {
        DB::table('audit_events')->insert($this->eventRow());
        DB::table('audit_integrity_checkpoints')->insert($this->checkpointRow());

        $this->assertQueryRejected(fn () => DB::table('audit_events')
            ->where('id', '018f6f7d-0c00-7000-8000-000000000201')
            ->update(['outcome' => 'failed']));
        $this->assertQueryRejected(fn () => DB::table('audit_integrity_checkpoints')
            ->where('id', '018f6f7d-0c00-7000-8000-000000000203')
            ->update(['status' => 'violated']));
        $this->assertQueryRejected(fn () => DB::table('audit_integrity_checkpoints')
            ->where('id', '018f6f7d-0c00-7000-8000-000000000203')
            ->delete());

        $this->assertSame('succeeded', DB::table('audit_events')->value('outcome'));
        $this->assertSame('verified', DB::table('audit_integrity_checkpoints')->value('status'));

        $this->assertSame(1, DB::table('audit_events')
            ->where('id', '018f6f7d-0c00-7000-8000-000000000201')
            ->delete());
        $this->assertDatabaseCount('audit_events', 0);
        $this->assertDatabaseCount('audit_integrity_checkpoints', 1);
    }

    public function test_sqlite_down_and_up_round_trip_restores_the_exact_schema_and_guards(): void
    {
        $before = $this->schemaSignature();

        ($this->migrationDown)();

        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $this->assertFalse(Schema::hasTable('audit_retention_policies'));

        ($this->migrationUp)();

        $this->assertSame($before, $this->schemaSignature());
        DB::table('audit_events')->insert($this->eventRow());
        $this->assertQueryRejected(fn () => DB::table('audit_events')->update(['outcome' => 'failed']));
    }

    /** @return list<string> */
    private function nullableColumns(string $table): array
    {
        $columns = array_map(
            static fn (array $column): string => $column['name'],
            array_filter(
                Schema::getColumns($table),
                static fn (array $column): bool => $column['nullable'],
            ),
        );
        sort($columns);

        return $columns;
    }

    /** @return array<string, string> */
    private function columnTypes(string $table): array
    {
        $types = [];
        foreach (Schema::getColumns($table) as $column) {
            $types[$column['name']] = $column['type_name'];
        }

        return $types;
    }

    private function assertSqlitePrimaryKey(string $table, string $column): void
    {
        $columns = DB::select('PRAGMA table_info("'.$table.'")');
        $primary = array_values(array_filter(
            $columns,
            static fn (object $metadata): bool => (int) $metadata->pk === 1,
        ));

        $this->assertCount(1, $primary);
        $this->assertSame($column, $primary[0]->name);
    }

    /** @param list<string> $columns */
    private function assertIndex(string $table, string $name, array $columns, bool $unique): void
    {
        $matches = array_values(array_filter(
            Schema::getIndexes($table),
            static fn (array $index): bool => $index['name'] === $name,
        ));

        $this->assertCount(1, $matches, "Missing index {$name} on {$table}.");
        $this->assertSame($columns, $matches[0]['columns']);
        $this->assertSame($unique, $matches[0]['unique']);
    }

    /** @return array<string, mixed> */
    private function schemaSignature(): array
    {
        $signature = [];
        foreach (self::TABLES as $table) {
            $indexes = Schema::getIndexes($table);
            usort($indexes, static fn (array $left, array $right): int => $left['name'] <=> $right['name']);
            $signature[$table] = [
                'columns' => Schema::getColumns($table),
                'indexes' => $indexes,
                'foreign_keys' => Schema::getForeignKeys($table),
            ];
        }
        $signature['triggers'] = array_map(
            static fn (object $trigger): array => [
                'name' => $trigger->name,
                'table' => $trigger->tbl_name,
                'sql' => preg_replace('/\s+/', ' ', trim((string) $trigger->sql)),
            ],
            DB::select(
                "SELECT name, tbl_name, sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name IN (?, ?, ?, ?) ORDER BY name",
                self::TABLES,
            ),
        );

        return $signature;
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    private function eventRow(array $changes = []): array
    {
        return [
            'id' => '018f6f7d-0c00-7000-8000-000000000201',
            'request_hash' => str_repeat('1', 64),
            'stream_key' => 'documents:document:018f6f7d-0c00-7000-8000-000000000299',
            'stream_sequence' => 1,
            'source_module' => 'documents',
            'action' => 'document.uploaded',
            'event_type' => 'com.cluster.documents.documentuploaded.v1',
            'actor_type' => 'user',
            'actor_id' => '018f6f7d-0c00-7000-8000-000000000211',
            'original_actor_id' => '018f6f7d-0c00-7000-8000-000000000211',
            'subject_type' => 'document',
            'subject_id' => '018f6f7d-0c00-7000-8000-000000000212',
            'correlation_id' => '018f6f7d-0c00-7000-8000-000000000213',
            'outcome' => 'succeeded',
            'classification' => 'confidential',
            'context' => json_encode(['method' => 'POST'], JSON_THROW_ON_ERROR),
            'context_schema_version' => 1,
            'redaction_policy_version' => 'v1',
            'occurred_at' => '2026-07-27 09:59:59.999',
            'recorded_at' => '2026-07-27 10:00:00.000',
            'retention_until' => '2033-07-26 10:00:00.000',
            'previous_hash' => null,
            'event_hash' => str_repeat('a', 64),
            'integrity_key_version' => 'v1',
            ...$changes,
        ];
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    private function exportRow(array $changes = []): array
    {
        return [
            'id' => '018f6f7d-0c00-7000-8000-000000000202',
            'principal_id' => '018f6f7d-0c00-7000-8000-000000000211',
            'facility_id' => null,
            'query' => json_encode(['classification' => 'confidential'], JSON_THROW_ON_ERROR),
            'query_hash' => str_repeat('b', 64),
            'reason_redacted' => '[REDACTED]',
            'format' => 'csv',
            'snapshot_recorded_at' => '2026-07-27 10:00:00.000',
            'status' => 'ready',
            'event_count' => 1,
            'lock_version' => 1,
            'expires_at' => '2026-07-28 10:00:00.000',
            'created_at' => '2026-07-27 10:00:00.000',
            'updated_at' => '2026-07-27 10:00:00.000',
            ...$changes,
        ];
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    private function checkpointRow(array $changes = []): array
    {
        return [
            'id' => '018f6f7d-0c00-7000-8000-000000000203',
            'stream_key' => 'documents:document:018f6f7d-0c00-7000-8000-000000000299',
            'kind' => 'verification',
            'first_sequence' => 1,
            'last_sequence' => 1,
            'event_count' => 1,
            'terminal_event_hash' => str_repeat('a', 64),
            'previous_checkpoint_hash' => null,
            'checkpoint_hash' => str_repeat('c', 64),
            'integrity_key_version' => 'v1',
            'status' => 'verified',
            'actor_id' => '018f6f7d-0c00-7000-8000-000000000211',
            'correlation_id' => '018f6f7d-0c00-7000-8000-000000000213',
            'details' => json_encode(['checked' => 1], JSON_THROW_ON_ERROR),
            'verified_at' => '2026-07-27 10:00:00.000',
            'created_at' => '2026-07-27 10:00:00.000',
            ...$changes,
        ];
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    private function idempotencyRow(array $changes = []): array
    {
        return [
            'id' => '018f6f7d-0c00-7000-8000-000000000204',
            'principal_id' => '018f6f7d-0c00-7000-8000-000000000211',
            'operation' => 'audit.exports.create',
            'key_hash' => str_repeat('d', 64),
            'request_hash' => str_repeat('e', 64),
            'response_status' => 201,
            'response_payload' => json_encode(['id' => '018f6f7d-0c00-7000-8000-000000000202'], JSON_THROW_ON_ERROR),
            'resource_id' => '018f6f7d-0c00-7000-8000-000000000202',
            'created_at' => '2026-07-27 10:00:00.000',
            'updated_at' => '2026-07-27 10:00:00.000',
            ...$changes,
        ];
    }

    private function assertQueryRejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected the database schema to reject the write.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
