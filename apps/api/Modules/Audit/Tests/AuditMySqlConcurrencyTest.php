<?php

declare(strict_types=1);

namespace Modules\Audit\Tests;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use PDOException;
use RuntimeException;
use Shared\Infrastructure\Outbox\DatabaseTransactionalOutbox;
use Tests\TestCase;
use Throwable;

final class AuditMySqlConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    private const STREAM_KEY = 'documents:document:018f6f7d-0c00-7000-8000-000000000399';

    private const INTEGRITY_KEY = 'audit-test-integrity-key-material-32-bytes';

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000313';

    private const ACTOR_ID = '018f6f7d-0c00-7000-8000-000000000311';

    private Closure $migrationUp;

    private Closure $migrationDown;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the explicit MySQL integration lane.');
        }

        $migration = require dirname(__DIR__).'/Infrastructure/Persistence/Migrations/CreateAuditTables.php';
        if (! is_object($migration)
            || ! method_exists($migration, 'up')
            || ! method_exists($migration, 'down')) {
            $this->fail('CreateAuditTables must return a migration with up() and down().');
        }
        $this->migrationUp = $migration->up(...);
        $this->migrationDown = $migration->down(...);
        if (! $this->hasExactAuditTables()) {
            ($this->migrationDown)();
            ($this->migrationUp)();
        }
    }

    public function test_two_connections_allocate_gap_free_same_stream_sequences_and_chain_previous_hashes_after_bounded_retry(): void
    {
        DB::disconnect();
        $workers = [
            $this->spawnConcurrentAppendWorker('018f6f7d-0c00-7000-8000-000000000301'),
            $this->spawnConcurrentAppendWorker('018f6f7d-0c00-7000-8000-000000000302'),
        ];

        foreach ($workers as $worker) {
            fwrite($worker['stream'], "select\n");
        }

        $readySignals = [];
        foreach ($workers as $worker) {
            stream_set_timeout($worker['stream'], 20);
            $readySignals[] = trim((string) fgets($worker['stream']));
        }
        foreach ($workers as $worker) {
            fwrite($worker['stream'], "insert\n");
        }

        $results = [];
        foreach ($workers as $worker) {
            $payload = stream_get_contents($worker['stream']);
            fclose($worker['stream']);
            pcntl_waitpid($worker['pid'], $status);
            $results[] = [
                'status' => $status,
                'payload' => $payload,
            ];
        }
        DB::reconnect();

        $this->assertSame(['ready', 'ready'], $readySignals);
        $decoded = [];
        foreach ($results as $result) {
            $this->assertTrue(pcntl_wifexited($result['status']));
            $this->assertSame(0, pcntl_wexitstatus($result['status']));
            $payload = json_decode($result['payload'], true, 16, JSON_THROW_ON_ERROR);
            $this->assertNull($payload['error']);
            $decoded[] = $payload;
        }

        usort($decoded, static fn (array $left, array $right): int => $left['sequence'] <=> $right['sequence']);
        $this->assertSame([1, 2], array_column($decoded, 'sequence'));
        $this->assertGreaterThanOrEqual(2, max(array_column($decoded, 'attempts')));

        $rows = DB::table('audit_events')
            ->where('stream_key', self::STREAM_KEY)
            ->orderBy('stream_sequence')
            ->get(['id', 'stream_sequence', 'previous_hash', 'event_hash']);
        $this->assertCount(2, $rows);
        $this->assertSame([1, 2], $rows->pluck('stream_sequence')->map(static fn (mixed $value): int => (int) $value)->all());
        $this->assertNull($rows[0]->previous_hash);
        $this->assertSame($rows[0]->event_hash, $rows[1]->previous_hash);
        $this->assertSame(
            self::eventHash((string) $rows[0]->id, self::STREAM_KEY, 1, null),
            $rows[0]->event_hash,
        );
        $this->assertSame(
            self::eventHash((string) $rows[1]->id, self::STREAM_KEY, 2, (string) $rows[0]->event_hash),
            $rows[1]->event_hash,
        );
    }

    public function test_outbox_exception_after_event_insert_rolls_back_the_event_transaction(): void
    {
        $eventId = '018f6f7d-0c00-7000-8000-000000000303';
        $outbox = new DatabaseTransactionalOutbox;
        $outbox->append(
            $eventId,
            '018f6f7d-0c00-7000-8000-000000000399',
            'com.cluster.audit.auditeventrecorded.v1',
            ['fixture' => 'preexisting'],
        );

        try {
            DB::transaction(function () use ($eventId, $outbox): void {
                DB::table('audit_events')->insert($this->eventRow(
                    $eventId,
                    self::STREAM_KEY,
                    1,
                    null,
                    self::eventHash($eventId, self::STREAM_KEY, 1, null),
                ));
                $outbox->append(
                    $eventId,
                    '018f6f7d-0c00-7000-8000-000000000399',
                    'com.cluster.audit.auditeventrecorded.v1',
                    ['fixture' => 'must-not-commit'],
                );
            });
            $this->fail('Expected the injected strict outbox duplicate to fail.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseMissing('audit_events', ['id' => $eventId]);
        $this->assertDatabaseCount('outbox_events', 1);
        $this->assertDatabaseHas('outbox_events', [
            'event_id' => $eventId,
            'event_type' => 'com.cluster.audit.auditeventrecorded.v1',
        ]);
    }

    public function test_mysql_guards_reject_event_update_and_checkpoint_update_or_delete(): void
    {
        $eventId = '018f6f7d-0c00-7000-8000-000000000304';
        $eventHash = self::eventHash($eventId, self::STREAM_KEY, 1, null);
        DB::table('audit_events')->insert($this->eventRow(
            $eventId,
            self::STREAM_KEY,
            1,
            null,
            $eventHash,
        ));
        DB::table('audit_integrity_checkpoints')->insert($this->checkpointRow(
            '018f6f7d-0c00-7000-8000-000000000305',
            self::STREAM_KEY,
            1,
            1,
            $eventHash,
            null,
        ));

        $this->assertMySqlRejected(
            'audit_events_immutable',
            fn () => DB::table('audit_events')->where('id', $eventId)->update(['outcome' => 'failed']),
        );
        $this->assertMySqlRejected(
            'audit_integrity_checkpoints_immutable',
            fn () => DB::table('audit_integrity_checkpoints')
                ->where('id', '018f6f7d-0c00-7000-8000-000000000305')
                ->update(['status' => 'violated']),
        );
        $this->assertMySqlRejected(
            'audit_integrity_checkpoints_immutable',
            fn () => DB::table('audit_integrity_checkpoints')
                ->where('id', '018f6f7d-0c00-7000-8000-000000000305')
                ->delete(),
        );

        $this->assertDatabaseHas('audit_events', ['id' => $eventId, 'outcome' => 'succeeded']);
        $this->assertDatabaseHas('audit_integrity_checkpoints', [
            'id' => '018f6f7d-0c00-7000-8000-000000000305',
            'status' => 'verified',
        ]);
    }

    public function test_mysql_schema_has_exact_column_types_checks_and_guard_inventory(): void
    {
        $expectedTypes = [
            'audit_events' => [
                'id' => 'char(36)',
                'request_hash' => 'char(64)',
                'stream_key' => 'varchar(160)',
                'stream_sequence' => 'bigint unsigned',
                'source_module' => 'varchar(64)',
                'action' => 'varchar(128)',
                'event_type' => 'varchar(160)',
                'actor_type' => 'varchar(16)',
                'actor_id' => 'char(36)',
                'original_actor_id' => 'char(36)',
                'subject_type' => 'varchar(64)',
                'subject_id' => 'char(36)',
                'correlation_id' => 'char(36)',
                'outcome' => 'varchar(16)',
                'classification' => 'varchar(32)',
                'context' => 'json',
                'context_schema_version' => 'smallint unsigned',
                'redaction_policy_version' => 'varchar(32)',
                'occurred_at' => 'datetime(3)',
                'recorded_at' => 'datetime(3)',
                'retention_until' => 'datetime(3)',
                'previous_hash' => 'char(64)',
                'event_hash' => 'char(64)',
                'integrity_key_version' => 'varchar(32)',
            ],
            'audit_export_jobs' => [
                'id' => 'char(36)',
                'principal_id' => 'char(36)',
                'facility_id' => 'char(36)',
                'query' => 'json',
                'query_hash' => 'char(64)',
                'reason_redacted' => 'varchar(500)',
                'format' => 'varchar(8)',
                'snapshot_recorded_at' => 'datetime(3)',
                'status' => 'varchar(16)',
                'event_count' => 'bigint unsigned',
                'lock_version' => 'bigint unsigned',
                'expires_at' => 'datetime(3)',
                'created_at' => 'timestamp(3)',
                'updated_at' => 'timestamp(3)',
            ],
            'audit_integrity_checkpoints' => [
                'id' => 'char(36)',
                'stream_key' => 'varchar(160)',
                'kind' => 'varchar(24)',
                'first_sequence' => 'bigint unsigned',
                'last_sequence' => 'bigint unsigned',
                'event_count' => 'bigint unsigned',
                'terminal_event_hash' => 'char(64)',
                'previous_checkpoint_hash' => 'char(64)',
                'checkpoint_hash' => 'char(64)',
                'integrity_key_version' => 'varchar(32)',
                'status' => 'varchar(16)',
                'actor_id' => 'char(36)',
                'correlation_id' => 'char(36)',
                'details' => 'json',
                'verified_at' => 'datetime(3)',
                'created_at' => 'datetime(3)',
            ],
            'audit_idempotency_keys' => [
                'id' => 'char(36)',
                'principal_id' => 'char(36)',
                'operation' => 'varchar(96)',
                'key_hash' => 'char(64)',
                'request_hash' => 'char(64)',
                'response_status' => 'smallint unsigned',
                'response_payload' => 'json',
                'resource_id' => 'char(36)',
                'created_at' => 'timestamp(3)',
                'updated_at' => 'timestamp(3)',
            ],
        ];

        foreach ($expectedTypes as $table => $types) {
            $actual = [];
            foreach (Schema::getColumns($table) as $column) {
                $actual[$column['name']] = $column['type'];
            }
            $this->assertSame($types, $actual);
        }

        $checks = array_map(
            static fn (object $row): string => $row->constraint_name,
            DB::select(
                <<<'SQL'
                    SELECT CONSTRAINT_NAME AS constraint_name
                    FROM information_schema.TABLE_CONSTRAINTS
                    WHERE CONSTRAINT_SCHEMA = ?
                      AND TABLE_NAME IN (?, ?, ?, ?)
                      AND CONSTRAINT_TYPE = 'CHECK'
                    ORDER BY CONSTRAINT_NAME
                    SQL,
                [DB::connection()->getDatabaseName(), ...self::auditTables()],
            ),
        );
        $this->assertSame([
            'audit_events_actor_type_check',
            'audit_events_classification_check',
            'audit_events_event_hash_check',
            'audit_events_outcome_check',
            'audit_events_previous_hash_check',
            'audit_events_request_hash_check',
            'audit_events_retention_check',
            'audit_events_stream_sequence_check',
            'audit_export_jobs_format_check',
            'audit_export_jobs_status_check',
            'audit_integrity_checkpoints_kind_check',
            'audit_integrity_checkpoints_status_check',
        ], $checks);

        $triggers = array_map(
            static fn (object $row): array => [
                $row->table_name,
                $row->trigger_name,
                $row->event_manipulation,
            ],
            DB::select(
                <<<'SQL'
                    SELECT EVENT_OBJECT_TABLE AS table_name,
                           TRIGGER_NAME AS trigger_name,
                           EVENT_MANIPULATION AS event_manipulation
                    FROM information_schema.TRIGGERS
                    WHERE TRIGGER_SCHEMA = ?
                      AND EVENT_OBJECT_TABLE IN (?, ?, ?, ?)
                    ORDER BY EVENT_OBJECT_TABLE, TRIGGER_NAME
                    SQL,
                [DB::connection()->getDatabaseName(), ...self::auditTables()],
            ),
        );
        $this->assertSame([
            ['audit_events', 'audit_events_update_prevent', 'UPDATE'],
            ['audit_integrity_checkpoints', 'audit_integrity_checkpoints_delete_prevent', 'DELETE'],
            ['audit_integrity_checkpoints', 'audit_integrity_checkpoints_update_prevent', 'UPDATE'],
        ], $triggers);
    }

    public function test_mysql_full_up_down_up_round_trip_restores_exact_columns_indexes_checks_and_guards(): void
    {
        $before = $this->mysqlSchemaSignature();

        ($this->migrationDown)();
        foreach (self::auditTables() as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $this->assertFalse(Schema::hasTable('audit_retention_policies'));

        ($this->migrationUp)();

        $this->assertSame($before, $this->mysqlSchemaSignature());
        foreach (self::auditTables() as $table) {
            $this->assertSame([], Schema::getForeignKeys($table));
        }
    }

    public function test_mysql_retention_checkpoint_and_prefix_delete_roll_back_together_on_injected_failure(): void
    {
        $events = $this->seedThreeEventRetentionStream('018f6f7d-0c00-7000-8000-00000000032');
        $checkpointId = '018f6f7d-0c00-7000-8000-000000000326';

        try {
            DB::transaction(function () use ($events, $checkpointId): void {
                DB::table('audit_integrity_checkpoints')->insert($this->checkpointRow(
                    $checkpointId,
                    self::STREAM_KEY,
                    1,
                    2,
                    $events[1]['event_hash'],
                    null,
                ));
                DB::table('audit_events')
                    ->where('stream_key', self::STREAM_KEY)
                    ->where('stream_sequence', '<=', 2)
                    ->delete();

                if (self::shouldInjectRetentionFailure()) {
                    throw new RuntimeException('injected_retention_outbox_failure');
                }
            });
            $this->fail('Expected the injected retention failure to roll back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('injected_retention_outbox_failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('audit_events', 3);
        $this->assertDatabaseMissing('audit_integrity_checkpoints', ['id' => $checkpointId]);
        $this->assertSame([1, 2, 3], DB::table('audit_events')
            ->where('stream_key', self::STREAM_KEY)
            ->orderBy('stream_sequence')
            ->pluck('stream_sequence')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all());
    }

    public function test_mysql_committed_retention_prefix_leaves_an_immutable_checkpoint_linking_the_surviving_chain(): void
    {
        $events = $this->seedThreeEventRetentionStream('018f6f7d-0c00-7000-8000-00000000033');
        $checkpointId = '018f6f7d-0c00-7000-8000-000000000336';
        $checkpoint = $this->checkpointRow(
            $checkpointId,
            self::STREAM_KEY,
            1,
            2,
            $events[1]['event_hash'],
            null,
        );

        DB::transaction(function () use ($checkpoint): void {
            DB::table('audit_integrity_checkpoints')->insert($checkpoint);
            DB::table('audit_events')
                ->where('stream_key', self::STREAM_KEY)
                ->where('stream_sequence', '<=', 2)
                ->where('retention_until', '<', '2026-07-27 00:00:00.000')
                ->delete();
        });

        $this->assertDatabaseCount('audit_events', 1);
        $survivor = DB::table('audit_events')->where('stream_key', self::STREAM_KEY)->first();
        $this->assertNotNull($survivor);
        $this->assertSame(3, (int) $survivor->stream_sequence);
        $this->assertSame($events[1]['event_hash'], $survivor->previous_hash);

        $storedCheckpoint = DB::table('audit_integrity_checkpoints')->where('id', $checkpointId)->first();
        $this->assertNotNull($storedCheckpoint);
        $this->assertSame($events[1]['event_hash'], $storedCheckpoint->terminal_event_hash);
        $this->assertSame($checkpoint['checkpoint_hash'], $storedCheckpoint->checkpoint_hash);
        $this->assertSame(
            self::checkpointHash(self::STREAM_KEY, 1, 2, 2, $events[1]['event_hash'], null),
            $storedCheckpoint->checkpoint_hash,
        );

        $this->assertMySqlRejected(
            'audit_integrity_checkpoints_immutable',
            fn () => DB::table('audit_integrity_checkpoints')->where('id', $checkpointId)->delete(),
        );
        $this->assertDatabaseHas('audit_integrity_checkpoints', ['id' => $checkpointId]);
    }

    /** @return array{pid: int, stream: resource} */
    private function spawnConcurrentAppendWorker(string $eventId): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            throw new RuntimeException('Unable to create the Audit MySQL concurrency socket.');
        }
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork the Audit MySQL concurrency worker.');
        }
        if ($pid === 0) {
            fclose($sockets[0]);
            try {
                $connection = $this->separateMySqlConnection();
                fgets($sockets[1]);
                $connection->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                $connection->beginTransaction();
                $tail = self::readStreamTail($connection, false);
                fwrite($sockets[1], "ready\n");
                fgets($sockets[1]);

                try {
                    $result = self::insertEventFromTail($connection, $eventId, $tail, 1);
                    $connection->commit();
                } catch (PDOException $exception) {
                    if ($connection->inTransaction()) {
                        $connection->rollBack();
                    }
                    if (! self::isRetryableMySqlRace($exception)) {
                        throw $exception;
                    }
                    $result = self::appendEventWithBoundedRetry($connection, $eventId, 2, 3);
                }
                $payload = [...$result, 'error' => null];
            } catch (Throwable $exception) {
                $payload = [
                    'sequence' => null,
                    'event_hash' => null,
                    'previous_hash' => null,
                    'attempts' => null,
                    'error' => $exception::class.':'.$exception->getMessage(),
                ];
            }

            fwrite($sockets[1], json_encode($payload, JSON_THROW_ON_ERROR));
            fclose($sockets[1]);
            exit(0);
        }

        fclose($sockets[1]);

        return ['pid' => $pid, 'stream' => $sockets[0]];
    }

    /** @return array{sequence: int, previous_hash: ?string, event_hash: string, attempts: int} */
    private static function appendEventWithBoundedRetry(
        PDO $connection,
        string $eventId,
        int $firstAttempt,
        int $maximumAttempt,
    ): array {
        for ($attempt = $firstAttempt; $attempt <= $maximumAttempt; $attempt++) {
            try {
                $connection->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                $connection->beginTransaction();
                $tail = self::readStreamTail($connection, true);
                $result = self::insertEventFromTail($connection, $eventId, $tail, $attempt);
                $connection->commit();

                return $result;
            } catch (PDOException $exception) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
                if (! self::isRetryableMySqlRace($exception) || $attempt === $maximumAttempt) {
                    throw $exception;
                }
                usleep(25_000 * $attempt);
            }
        }

        throw new RuntimeException('Audit MySQL bounded retry exhausted unexpectedly.');
    }

    /** @return array{stream_sequence: int, event_hash: string}|null */
    private static function readStreamTail(PDO $connection, bool $forUpdate): ?array
    {
        $sql = <<<'SQL'
            SELECT stream_sequence, event_hash
            FROM audit_events
            WHERE stream_key = ?
            ORDER BY stream_sequence DESC
            LIMIT 1
            SQL;
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $connection->prepare($sql);
        $statement->execute([self::STREAM_KEY]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return [
            'stream_sequence' => (int) $row['stream_sequence'],
            'event_hash' => (string) $row['event_hash'],
        ];
    }

    /**
     * @param  array{stream_sequence: int, event_hash: string}|null  $tail
     * @return array{sequence: int, previous_hash: ?string, event_hash: string, attempts: int}
     */
    private static function insertEventFromTail(PDO $connection, string $eventId, ?array $tail, int $attempt): array
    {
        $sequence = ($tail['stream_sequence'] ?? 0) + 1;
        $previousHash = $tail['event_hash'] ?? null;
        $eventHash = self::eventHash($eventId, self::STREAM_KEY, $sequence, $previousHash);
        $row = self::rawEventRow($eventId, self::STREAM_KEY, $sequence, $previousHash, $eventHash);
        $columns = array_keys($row);
        $statement = $connection->prepare(sprintf(
            'INSERT INTO audit_events (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?')),
        ));
        $statement->execute(array_values($row));

        return [
            'sequence' => $sequence,
            'previous_hash' => $previousHash,
            'event_hash' => $eventHash,
            'attempts' => $attempt,
        ];
    }

    private static function isRetryableMySqlRace(PDOException $exception): bool
    {
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return in_array((string) $exception->getCode(), ['23000', '40001'], true)
            || in_array($driverCode, [1062, 1205, 1213], true);
    }

    private function separateMySqlConnection(): PDO
    {
        /** @var array{host: string, port: int|string, database: string, username: string, password: string} $configuration */
        $configuration = config('database.connections.mysql');

        return new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $configuration['host'],
                $configuration['port'],
                $configuration['database'],
            ),
            $configuration['username'],
            $configuration['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ],
        );
    }

    private function hasExactAuditTables(): bool
    {
        foreach (self::auditTables() as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return Schema::hasColumns('audit_events', ['request_hash', 'stream_key', 'previous_hash', 'integrity_key_version'])
            && Schema::hasColumns('audit_export_jobs', ['principal_id', 'query_hash', 'snapshot_recorded_at'])
            && Schema::hasColumns('audit_integrity_checkpoints', ['stream_key', 'checkpoint_hash'])
            && Schema::hasColumns('audit_idempotency_keys', ['response_status', 'response_payload']);
    }

    /** @return list<string> */
    private static function auditTables(): array
    {
        return [
            'audit_events',
            'audit_export_jobs',
            'audit_integrity_checkpoints',
            'audit_idempotency_keys',
        ];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function mysqlSchemaSignature(): array
    {
        $database = DB::connection()->getDatabaseName();
        $tablePlaceholders = implode(', ', array_fill(0, count(self::auditTables()), '?'));
        $bindings = [$database, ...self::auditTables()];
        $queries = [
            'columns' => <<<SQL
                SELECT TABLE_NAME AS table_name, ORDINAL_POSITION AS ordinal_position,
                       COLUMN_NAME AS column_name, COLUMN_TYPE AS column_type,
                       IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default,
                       EXTRA AS extra
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ({$tablePlaceholders})
                ORDER BY TABLE_NAME, ORDINAL_POSITION
                SQL,
            'indexes' => <<<SQL
                SELECT TABLE_NAME AS table_name, INDEX_NAME AS index_name,
                       NON_UNIQUE AS non_unique, SEQ_IN_INDEX AS seq_in_index,
                       COLUMN_NAME AS column_name
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ({$tablePlaceholders})
                ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX
                SQL,
            'constraints' => <<<SQL
                SELECT TABLE_NAME AS table_name, CONSTRAINT_NAME AS constraint_name,
                       CONSTRAINT_TYPE AS constraint_type
                FROM information_schema.TABLE_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME IN ({$tablePlaceholders})
                ORDER BY TABLE_NAME, CONSTRAINT_NAME
                SQL,
            'checks' => <<<SQL
                SELECT tc.TABLE_NAME AS table_name, tc.CONSTRAINT_NAME AS constraint_name,
                       cc.CHECK_CLAUSE AS check_clause
                FROM information_schema.TABLE_CONSTRAINTS tc
                INNER JOIN information_schema.CHECK_CONSTRAINTS cc
                    ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
                   AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
                WHERE tc.CONSTRAINT_SCHEMA = ?
                  AND tc.TABLE_NAME IN ({$tablePlaceholders})
                  AND tc.CONSTRAINT_TYPE = 'CHECK'
                ORDER BY tc.TABLE_NAME, tc.CONSTRAINT_NAME
                SQL,
            'triggers' => <<<SQL
                SELECT EVENT_OBJECT_TABLE AS table_name, TRIGGER_NAME AS trigger_name,
                       EVENT_MANIPULATION AS event_manipulation, ACTION_TIMING AS action_timing,
                       ACTION_STATEMENT AS action_statement
                FROM information_schema.TRIGGERS
                WHERE TRIGGER_SCHEMA = ? AND EVENT_OBJECT_TABLE IN ({$tablePlaceholders})
                ORDER BY EVENT_OBJECT_TABLE, TRIGGER_NAME
                SQL,
            'foreign_keys' => <<<SQL
                SELECT TABLE_NAME AS table_name, CONSTRAINT_NAME AS constraint_name,
                       COLUMN_NAME AS column_name, REFERENCED_TABLE_NAME AS referenced_table_name
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = ?
                  AND TABLE_NAME IN ({$tablePlaceholders})
                  AND REFERENCED_TABLE_NAME IS NOT NULL
                ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION
                SQL,
        ];

        $signature = [];
        foreach ($queries as $name => $query) {
            $signature[$name] = array_map(
                static fn (object $row): array => array_map(
                    static fn (mixed $value): mixed => is_string($value)
                        ? preg_replace('/\s+/', ' ', trim($value))
                        : $value,
                    (array) $row,
                ),
                DB::select($query, $bindings),
            );
        }

        return $signature;
    }

    /** @return list<array{id: string, event_hash: string}> */
    private function seedThreeEventRetentionStream(string $idPrefix): array
    {
        $events = [];
        $previousHash = null;
        foreach ([1, 2, 3] as $sequence) {
            $id = $idPrefix.$sequence;
            $eventHash = self::eventHash($id, self::STREAM_KEY, $sequence, $previousHash);
            DB::table('audit_events')->insert($this->eventRow(
                $id,
                self::STREAM_KEY,
                $sequence,
                $previousHash,
                $eventHash,
                $sequence < 3 ? '2018-07-27 10:00:00.000' : '2026-07-27 10:00:00.000',
                $sequence < 3 ? '2025-07-27 10:00:00.000' : '2033-07-26 10:00:00.000',
            ));
            $events[] = ['id' => $id, 'event_hash' => $eventHash];
            $previousHash = $eventHash;
        }

        return $events;
    }

    /** @return array<string, mixed> */
    private function eventRow(
        string $id,
        string $streamKey,
        int $sequence,
        ?string $previousHash,
        string $eventHash,
        string $recordedAt = '2026-07-27 10:00:00.000',
        string $retentionUntil = '2033-07-26 10:00:00.000',
    ): array {
        return self::rawEventRow(
            $id,
            $streamKey,
            $sequence,
            $previousHash,
            $eventHash,
            $recordedAt,
            $retentionUntil,
        );
    }

    /** @return array<string, mixed> */
    private static function rawEventRow(
        string $id,
        string $streamKey,
        int $sequence,
        ?string $previousHash,
        string $eventHash,
        string $recordedAt = '2026-07-27 10:00:00.000',
        string $retentionUntil = '2033-07-26 10:00:00.000',
    ): array {
        return [
            'id' => $id,
            'request_hash' => hash('sha256', $id),
            'stream_key' => $streamKey,
            'stream_sequence' => $sequence,
            'source_module' => 'documents',
            'action' => 'document.uploaded',
            'event_type' => 'com.cluster.documents.documentuploaded.v1',
            'actor_type' => 'user',
            'actor_id' => self::ACTOR_ID,
            'original_actor_id' => self::ACTOR_ID,
            'subject_type' => 'document',
            'subject_id' => '018f6f7d-0c00-7000-8000-000000000312',
            'correlation_id' => self::CORRELATION_ID,
            'outcome' => 'succeeded',
            'classification' => 'confidential',
            'context' => json_encode(['method' => 'POST'], JSON_THROW_ON_ERROR),
            'context_schema_version' => 1,
            'redaction_policy_version' => 'v1',
            'occurred_at' => $recordedAt,
            'recorded_at' => $recordedAt,
            'retention_until' => $retentionUntil,
            'previous_hash' => $previousHash,
            'event_hash' => $eventHash,
            'integrity_key_version' => 'v1',
        ];
    }

    /** @return array<string, mixed> */
    private function checkpointRow(
        string $id,
        string $streamKey,
        int $firstSequence,
        int $lastSequence,
        string $terminalEventHash,
        ?string $previousCheckpointHash,
    ): array {
        $eventCount = $lastSequence - $firstSequence + 1;

        return [
            'id' => $id,
            'stream_key' => $streamKey,
            'kind' => 'retention_purge',
            'first_sequence' => $firstSequence,
            'last_sequence' => $lastSequence,
            'event_count' => $eventCount,
            'terminal_event_hash' => $terminalEventHash,
            'previous_checkpoint_hash' => $previousCheckpointHash,
            'checkpoint_hash' => self::checkpointHash(
                $streamKey,
                $firstSequence,
                $lastSequence,
                $eventCount,
                $terminalEventHash,
                $previousCheckpointHash,
            ),
            'integrity_key_version' => 'v1',
            'status' => 'verified',
            'actor_id' => self::ACTOR_ID,
            'correlation_id' => self::CORRELATION_ID,
            'details' => json_encode(['reason' => 'retention'], JSON_THROW_ON_ERROR),
            'verified_at' => '2026-07-27 10:00:00.000',
            'created_at' => '2026-07-27 10:00:00.000',
        ];
    }

    private static function eventHash(
        string $eventId,
        string $streamKey,
        int $sequence,
        ?string $previousHash,
    ): string {
        return hash_hmac('sha256', json_encode([
            'event_id' => $eventId,
            'stream_key' => $streamKey,
            'stream_sequence' => $sequence,
            'previous_hash' => $previousHash,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), self::INTEGRITY_KEY);
    }

    private static function checkpointHash(
        string $streamKey,
        int $firstSequence,
        int $lastSequence,
        int $eventCount,
        string $terminalEventHash,
        ?string $previousCheckpointHash,
    ): string {
        return hash_hmac('sha256', json_encode([
            'stream_key' => $streamKey,
            'kind' => 'retention_purge',
            'first_sequence' => $firstSequence,
            'last_sequence' => $lastSequence,
            'event_count' => $eventCount,
            'terminal_event_hash' => $terminalEventHash,
            'previous_checkpoint_hash' => $previousCheckpointHash,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), self::INTEGRITY_KEY);
    }

    private static function shouldInjectRetentionFailure(): bool
    {
        return true;
    }

    private function assertMySqlRejected(string $message, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Expected MySQL to reject the write with {$message}.");
        } catch (QueryException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
