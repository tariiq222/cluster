<?php

namespace Modules\Organization\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Organization\Features\TemporaryAssignment\Contracts\ValidateTemporaryAssignmentCapabilities;
use Modules\Organization\Features\TemporaryAssignment\Events\TemporaryAssignmentEventFactory;
use Modules\Organization\Features\TemporaryAssignment\Handler\ExpireTemporaryAssignmentsHandler;
use Modules\Organization\Features\TemporaryAssignment\Handler\TemporaryAssignmentHandler;
use Modules\Organization\Infrastructure\Outbox\OrganizationOutbox;
use PDO;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class TemporaryAssignmentMySqlConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    private const ACTOR_ID = '018f6f7d-0c00-7000-8000-000000000721';

    private const CLUSTER_ID = '018f6f7d-0c00-7000-8000-000000000722';

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000723';

    private const PERSON_ID = '018f6f7d-0c00-7000-8000-000000000724';

    private const UNIT_ID = '018f6f7d-0c00-7000-8000-000000000726';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the explicit MySQL integration lane.');
        }
        CarbonImmutable::setTestNow('2026-07-18T12:00:00.000Z');
        $this->seedOrganizationReferences();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_expiration_skips_a_row_locked_by_another_mysql_worker(): void
    {
        $this->seedDueAssignment('018f6f7d-0c00-7000-8000-000000000727', 'records.read');
        $this->seedDueAssignment('018f6f7d-0c00-7000-8000-000000000728', 'records.export');
        $locker = $this->separateMySqlConnection();
        $locker->beginTransaction();
        $lockedId = (string) $locker->query(
            "SELECT id FROM temporary_assignments WHERE state = 'active' ORDER BY id LIMIT 1 FOR UPDATE",
        )->fetchColumn();

        try {
            $result = (new ExpireTemporaryAssignmentsHandler(
                new OrganizationOutbox,
                new TemporaryAssignmentEventFactory,
            ))->handle(1, self::ACTOR_ID, self::CORRELATION_ID);

            $this->assertSame(1, $result['expired_count']);
            $this->assertNotSame($lockedId, $result['expired_ids'][0]);
            $this->assertTrue($result['has_more']);
            $this->assertDatabaseHas('temporary_assignments', ['id' => $lockedId, 'state' => 'active']);
            $this->assertDatabaseHas('temporary_assignments', ['id' => $result['expired_ids'][0], 'state' => 'expired']);
        } finally {
            $locker->rollBack();
        }
    }

    public function test_overlapping_creates_serialize_and_one_is_rejected_after_root_lock_release(): void
    {
        DB::disconnect();
        $workers = [
            $this->spawnCreateWorker(
                '018f6f7d-0c00-7000-8000-000000000730',
                'mysql-overlap-create-a',
            ),
            $this->spawnCreateWorker(
                '018f6f7d-0c00-7000-8000-000000000731',
                'mysql-overlap-create-b',
            ),
        ];

        $locker = $this->separateMySqlConnection();
        $locker->beginTransaction();
        $statement = $locker->prepare('SELECT id FROM people WHERE id = ? FOR UPDATE');
        $statement->execute([self::PERSON_ID]);
        $this->assertSame(self::PERSON_ID, $statement->fetchColumn());

        foreach ($workers as $worker) {
            fwrite($worker['stream'], "go\n");
        }
        usleep(200_000);
        $locker->rollBack();

        $outcomes = [];
        foreach ($workers as $worker) {
            stream_set_timeout($worker['stream'], 15);
            $payload = stream_get_contents($worker['stream']);
            fclose($worker['stream']);
            pcntl_waitpid($worker['pid'], $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
            $decoded = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
            $outcomes[] = $decoded['outcome'];
        }

        sort($outcomes);
        $this->assertSame(['created', 'temporary_assignment_capability_overlap'], $outcomes);
        DB::reconnect();
        $this->assertDatabaseCount('temporary_assignments', 1);
        $this->assertDatabaseCount('temporary_assignment_capabilities', 1);
        $this->assertDatabaseCount('organization_idempotency_keys', 1);
        $this->assertDatabaseCount('outbox_events', 1);
    }

    public function test_mysql_check_constraints_reject_direct_invalid_writes(): void
    {
        $this->assertMySqlConstraint(
            'temporary_assignments_window_check',
            fn () => DB::table('temporary_assignments')->insert($this->rawAssignment([
                'end_at' => '2026-07-19 10:00:00.000',
            ])),
        );
        $this->assertMySqlConstraint(
            'temporary_assignments_window_check',
            fn () => DB::table('temporary_assignments')->insert($this->rawAssignment([
                'end_at' => '2026-10-18 10:00:00.001',
            ])),
        );
        foreach (['   ', str_repeat('x', 2001)] as $reason) {
            $this->assertMySqlConstraint(
                'temporary_assignments_reason_check',
                fn () => DB::table('temporary_assignments')->insert($this->rawAssignment(['reason' => $reason])),
            );
        }
        $this->assertMySqlConstraint(
            'temporary_assignments_revocation_check',
            fn () => DB::table('temporary_assignments')->insert($this->rawAssignment(['state' => 'revoked'])),
        );
        $this->assertMySqlConstraint(
            'temporary_assignments_revocation_check',
            fn () => DB::table('temporary_assignments')->insert($this->rawAssignment([
                'state' => 'active',
                'revoked_at' => '2026-07-18 12:00:00.000',
                'revoked_by_user_id' => self::ACTOR_ID,
                'revocation_reason' => 'حالة متناقضة',
            ])),
        );
        $this->assertMySqlConstraint(
            'temporary_assignments_lock_version_check',
            fn () => DB::table('temporary_assignments')->insert($this->rawAssignment(['lock_version' => 0])),
        );

        $assignmentId = '018f6f7d-0c00-7000-8000-000000000732';
        DB::table('temporary_assignments')->insert($this->rawAssignment(['id' => $assignmentId]));
        foreach (['records.*', 'records.?', 'records.%', 'records_read'] as $capabilityCode) {
            $this->assertMySqlConstraint(
                'temporary_assignment_capability_format_check',
                fn () => DB::table('temporary_assignment_capabilities')->insert([
                    'temporary_assignment_id' => $assignmentId,
                    'capability_code' => $capabilityCode,
                ]),
            );
        }
        $this->assertDatabaseCount('temporary_assignments', 1);
        $this->assertDatabaseCount('temporary_assignment_capabilities', 0);
    }

    /** @return array{pid: int, stream: resource} */
    private function spawnCreateWorker(string $assignmentId, string $operation): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            throw new RuntimeException('Unable to create the MySQL concurrency worker socket.');
        }
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork the MySQL concurrency worker.');
        }
        if ($pid === 0) {
            fclose($sockets[0]);
            fgets($sockets[1]);
            DB::purge();

            $input = [
                'person_id' => self::PERSON_ID,
                'organization_unit_id' => self::UNIT_ID,
                'capability_codes' => ['records.read'],
                'start_at' => '2026-07-18T13:00:00.000Z',
                'end_at' => '2026-07-18T14:00:00.000Z',
                'reason' => 'اختبار تسلسل الإنشاء المتداخل',
            ];
            try {
                (new TemporaryAssignmentHandler(
                    new OrganizationOutbox,
                    new TemporaryAssignmentEventFactory,
                    new AlwaysActiveTemporaryAssignmentCapabilityValidator,
                ))->create(
                    $assignmentId,
                    $input,
                    self::ACTOR_ID,
                    self::CORRELATION_ID,
                    [
                        'principal_id' => self::ACTOR_ID,
                        'operation' => $operation,
                        'key_hash' => hash('sha256', $operation),
                        'request_hash' => hash('sha256', json_encode($input, JSON_THROW_ON_ERROR)),
                    ],
                );
                $outcome = 'created';
            } catch (Throwable $exception) {
                $outcome = $exception->getMessage();
            }

            fwrite($sockets[1], json_encode(['outcome' => $outcome], JSON_THROW_ON_ERROR));
            fclose($sockets[1]);
            exit(0);
        }

        fclose($sockets[1]);

        return ['pid' => $pid, 'stream' => $sockets[0]];
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
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private function seedOrganizationReferences(): void
    {
        $now = now();
        DB::table('clusters')->insert([
            'id' => self::CLUSTER_ID,
            'code' => 'TEMP-MYSQL',
            'name_ar' => 'تجمع اختبار القفل',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('unit_types')->insert([
            'id' => '018f6f7d-0c00-7000-8000-000000000729',
            'code' => 'temporary_mysql_test',
            'name_ar' => 'وحدة اختبار القفل',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('organization_units')->insert([
            'id' => self::UNIT_ID,
            'cluster_id' => self::CLUSTER_ID,
            'parent_id' => self::CLUSTER_ID,
            'parent_type' => 'cluster',
            'unit_type_id' => '018f6f7d-0c00-7000-8000-000000000729',
            'code' => 'TEMP-MYSQL-UNIT',
            'name_ar' => 'الوحدة المستهدفة',
            'status' => 'active',
            'path_cache' => '/'.self::UNIT_ID,
            'depth' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('people')->insert([
            'id' => self::PERSON_ID,
            'employee_number' => 'TEMP-MYSQL-EMP',
            'display_name_ar' => 'موظف اختبار القفل',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedDueAssignment(string $id, string $capabilityCode): void
    {
        DB::table('temporary_assignments')->insert([
            'id' => $id,
            'person_id' => self::PERSON_ID,
            'organization_unit_id' => self::UNIT_ID,
            'start_at' => '2026-07-18 10:00:00.000',
            'end_at' => '2026-07-18 11:00:00.000',
            'state' => 'active',
            'reason' => 'اختبار التنافس بين عمال الانتهاء',
            'approved_by_user_id' => self::ACTOR_ID,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('temporary_assignment_capabilities')->insert([
            'temporary_assignment_id' => $id,
            'capability_code' => $capabilityCode,
        ]);
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    private function rawAssignment(array $changes = []): array
    {
        return [
            'id' => Str::uuid7()->toString(),
            'person_id' => self::PERSON_ID,
            'organization_unit_id' => self::UNIT_ID,
            'start_at' => '2026-07-19 10:00:00.000',
            'end_at' => '2026-07-20 10:00:00.000',
            'state' => 'pending',
            'reason' => 'سبب صالح',
            'approved_by_user_id' => self::ACTOR_ID,
            'revoked_at' => null,
            'revoked_by_user_id' => null,
            'revocation_reason' => null,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
            ...$changes,
        ];
    }

    private function assertMySqlConstraint(string $constraint, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Expected MySQL constraint {$constraint} to reject the write.");
        } catch (QueryException $exception) {
            $this->assertStringContainsString($constraint, $exception->getMessage());
        }
    }
}

final class AlwaysActiveTemporaryAssignmentCapabilityValidator implements ValidateTemporaryAssignmentCapabilities
{
    public function allAreActive(array $capabilityCodes): bool
    {
        return $capabilityCodes !== [];
    }
}
