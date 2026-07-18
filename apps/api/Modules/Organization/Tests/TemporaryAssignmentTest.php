<?php

namespace Modules\Organization\Tests;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Organization\Contracts\ListActiveTemporaryAssignmentFacts;
use Modules\Organization\Features\TemporaryAssignment\Contracts\ValidateTemporaryAssignmentCapabilities;
use Modules\Organization\Features\TemporaryAssignment\Events\BuildTemporaryAssignmentEvent;
use Modules\Organization\Features\TemporaryAssignment\Events\TemporaryAssignmentEventFactory;
use Modules\Organization\Features\TemporaryAssignment\Exceptions\TemporaryAssignmentIdempotencyConflict;
use Modules\Organization\Features\TemporaryAssignment\Handler\ExpireTemporaryAssignmentsHandler;
use Modules\Organization\Features\TemporaryAssignment\Handler\TemporaryAssignmentExpirationLock;
use Modules\Organization\Features\TemporaryAssignment\Handler\TemporaryAssignmentHandler;
use Modules\Organization\Features\TemporaryAssignment\Query\DatabaseListActiveTemporaryAssignmentFacts;
use Modules\Organization\Infrastructure\Outbox\OrganizationOutbox;
use RuntimeException;
use Tests\TestCase;

class TemporaryAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private const ACTOR_ID = '018f6f7d-0c00-7000-8000-000000000701';

    private const CLUSTER_ID = '018f6f7d-0c00-7000-8000-000000000702';

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000703';

    private const PERSON_ID = '018f6f7d-0c00-7000-8000-000000000704';

    private const UNIT_ID = '018f6f7d-0c00-7000-8000-000000000706';

    private const SECOND_UNIT_ID = '018f6f7d-0c00-7000-8000-000000000707';

    private const UNIT_TYPE_ID = '018f6f7d-0c00-7000-8000-000000000708';

    private TemporaryAssignmentHandler $handler;

    private MutableTemporaryAssignmentCapabilityValidator $capabilityValidator;

    protected function beforeRefreshingDatabase(): void
    {
        if (Schema::hasTable('people') && ! Schema::hasTable('temporary_assignments')) {
            $this->migrateTemporaryAssignments();
        }
    }

    protected function migrateDatabases(): void
    {
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
    }

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-18T10:00:00.000Z');
        $this->seedOrganizationReferences();

        $this->capabilityValidator = new MutableTemporaryAssignmentCapabilityValidator([
            'records.approve',
            'records.assign',
            'records.delete',
            'records.export',
            'records.read',
        ]);
        $this->handler = new TemporaryAssignmentHandler(
            new OrganizationOutbox,
            new TemporaryAssignmentEventFactory,
            $this->capabilityValidator,
        );
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_create_uses_exact_unit_scope_and_is_safely_idempotent(): void
    {
        $input = $this->validInput();
        $idempotency = $this->idempotency('temporary-create', $input);

        $result = $this->handler->create(
            Str::uuid7()->toString(),
            $input,
            self::ACTOR_ID,
            self::CORRELATION_ID,
            $idempotency,
        );

        $assignment = $result['temporary_assignment'];
        $this->assertTrue($result['created']);
        $this->assertSame(self::UNIT_ID, $assignment['organization_unit_id']);
        $this->assertFalse(Schema::hasColumn('temporary_assignments', 'position_id'));
        $this->assertSame(['records.approve', 'records.read'], $assignment['capability_codes']);
        $this->assertSame('pending', $assignment['state']);
        $this->assertSame(1, $assignment['lock_version']);
        $this->assertStringStartsWith('W/"temporary-assignment-', $assignment['representation_etag']);
        $pendingEtag = $assignment['representation_etag'];

        CarbonImmutable::setTestNow('2026-07-20T10:00:00.000Z');
        $current = $this->handler->find($assignment['id']);
        $this->assertIsArray($current);
        $this->assertSame('active', $current['state']);
        $this->assertSame(1, $current['lock_version']);
        $this->assertNotSame($pendingEtag, $current['representation_etag']);
        $this->assertSame('2026-07-20T10:00:00.000Z', $current['state_evaluated_at']);

        $replay = $this->handler->create(
            Str::uuid7()->toString(),
            $input,
            self::ACTOR_ID,
            self::CORRELATION_ID,
            $idempotency,
        );
        $this->assertFalse($replay['created']);
        $this->assertSame($assignment['id'], $replay['temporary_assignment']['id']);

        $this->assertIdempotencyConflict(
            fn () => $this->handler->create(
                Str::uuid7()->toString(),
                $input,
                self::ACTOR_ID,
                self::CORRELATION_ID,
                [...$idempotency, 'request_hash' => str_repeat('f', 64)],
            ),
        );
        $this->assertDatabaseCount('temporary_assignments', 1);
        $this->assertDatabaseCount('temporary_assignment_capabilities', 2);
        $this->assertDatabaseCount('outbox_events', 1);

        $event = json_decode((string) DB::table('outbox_events')->value('cloud_event'), true, 32, JSON_THROW_ON_ERROR);
        $this->assertEqualsCanonicalizing(
            ['id', 'person_id', 'organization_unit_id', 'capability_codes', 'start_at', 'end_at', 'state', 'lock_version'],
            array_keys($event['data']['temporary_assignment']),
        );
        $this->assertSame('internal', $event['data']['classification']);
        $this->assertEqualsCanonicalizing([
            'subject_id' => self::ACTOR_ID,
            'tenant_id' => self::CLUSTER_ID,
            'organization_unit_ids' => [self::UNIT_ID],
            'roles' => [],
            'clearance' => 'internal',
            'break_glass' => false,
            'correlation_id' => self::CORRELATION_ID,
        ], $event['data']['access_context']);
        foreach ([
            'reason',
            'approved_by_user_id',
            'revocation_reason',
            'revoked_by_user_id',
            'display_name',
            'employee_number',
            'national_id',
            'email',
            'phone',
            'position_id',
            'access_token',
            'secret',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) DB::table('outbox_events')->value('cloud_event'));
        }
    }

    public function test_create_enforces_backdating_duration_reason_and_capability_policy(): void
    {
        $valid = $this->validInput();
        $cases = [
            'temporary_assignment_backdated' => [...$valid, 'start_at' => $this->at('-1 millisecond')],
            'temporary_assignment_window_invalid' => [...$valid, 'end_at' => $valid['start_at']],
            'temporary_assignment_window_too_long' => [...$valid, 'end_at' => $this->at('+91 days +1 millisecond')],
            'temporary_assignment_reason_invalid' => [...$valid, 'reason' => '   '],
            'temporary_assignment_reason_too_long' => [...$valid, 'reason' => str_repeat('x', 2001)],
            'temporary_assignment_capabilities_required' => [...$valid, 'capability_codes' => []],
            'temporary_assignment_capability_duplicate' => [...$valid, 'capability_codes' => ['records.read', 'records.read']],
            'temporary_assignment_capability_invalid' => [...$valid, 'capability_codes' => ['records.*']],
            'temporary_assignment_position_scope_not_supported' => [
                ...$valid,
                'position_id' => '018f6f7d-0c00-7000-8000-000000000799',
            ],
        ];

        foreach ($cases as $message => $input) {
            $this->assertInvalid(
                fn () => $this->handler->create(
                    Str::uuid7()->toString(),
                    $input,
                    self::ACTOR_ID,
                    self::CORRELATION_ID,
                    $this->idempotency($message, $input),
                ),
                $message,
            );
        }

        $maximumWindow = [...$valid, 'end_at' => $this->at('+91 days')];
        $accepted = $this->create($maximumWindow, 'temporary-assignment-maximum-window');
        $this->assertSame('pending', $accepted['temporary_assignment']['state']);
        $this->assertDatabaseCount('temporary_assignments', 1);
        $this->assertDatabaseCount('organization_idempotency_keys', 1);
    }

    public function test_create_requires_an_active_person_and_exact_active_unit(): void
    {
        DB::table('people')->where('id', self::PERSON_ID)->update(['status' => 'suspended']);
        $this->assertDomainFailure(
            fn () => $this->create($this->validInput(), 'inactive-person'),
            'person_inactive',
        );
        DB::table('people')->where('id', self::PERSON_ID)->update(['status' => 'active']);

        $this->seedUnit(self::SECOND_UNIT_ID, 'TEMP-INACTIVE', 'archived');
        $this->assertDomainFailure(
            fn () => $this->create($this->validInput(['organization_unit_id' => self::SECOND_UNIT_ID]), 'inactive-unit'),
            'organization_unit_inactive',
        );
        $this->assertDomainFailure(
            fn () => $this->create($this->validInput([
                'organization_unit_id' => '018f6f7d-0c00-7000-8000-000000000799',
            ]), 'missing-unit'),
            'organization_unit_not_found',
        );
        $this->assertDatabaseCount('temporary_assignments', 0);
    }

    public function test_governed_capability_validation_fails_closed_on_write_and_active_fact_read(): void
    {
        $this->capabilityValidator->deactivate('records.delete');
        $this->assertDomainFailure(
            fn () => $this->create($this->validInput(['capability_codes' => ['records.delete']]), 'capability-inactive'),
            'temporary_assignment_capability_not_active',
        );
        $this->assertDomainFailure(
            fn () => $this->create($this->validInput(['capability_codes' => ['records.unknown']]), 'capability-unknown'),
            'temporary_assignment_capability_not_active',
        );

        $this->capabilityValidator->failValidation();
        $this->assertDomainFailure(
            fn () => $this->create($this->validInput(['capability_codes' => ['records.read']]), 'capability-unavailable'),
            'temporary_assignment_capability_validation_unavailable',
        );
        $this->assertDatabaseCount('temporary_assignments', 0);
        $this->assertDatabaseCount('organization_idempotency_keys', 0);

        $this->capabilityValidator->recover();
        $this->create($this->validInput([
            'start_at' => $this->at(),
            'end_at' => $this->at('+1 day'),
            'capability_codes' => ['records.read'],
        ]), 'capability-active-fact');
        $this->capabilityValidator->deactivate('records.read');
        $this->assertSame([], $this->facts()->forPerson(self::PERSON_ID));

        $this->capabilityValidator->failValidation();
        $this->assertSame([], $this->facts()->forPerson(self::PERSON_ID));
    }

    public function test_no_backdate_is_rechecked_with_a_fresh_clock_immediately_before_insert(): void
    {
        $input = $this->validInput([
            'start_at' => $this->at('+1 minute'),
            'end_at' => $this->at('+1 hour'),
        ]);
        $this->capabilityValidator->advanceClockTo('2026-07-18T10:02:00.000Z');

        $this->assertInvalid(
            fn () => $this->create($input, 'fresh-clock-backdate'),
            'temporary_assignment_backdated',
        );
        $this->assertDatabaseCount('temporary_assignments', 0);
        $this->assertDatabaseCount('organization_idempotency_keys', 0);
    }

    public function test_same_capability_cannot_overlap_for_the_same_person_and_unit(): void
    {
        $first = $this->validInput(['capability_codes' => ['records.read']]);
        $this->create($first, 'overlap-first');

        $overlap = [...$first, 'start_at' => $this->at('+2 days'), 'end_at' => $this->at('+4 days')];
        $this->assertDomainFailure(
            fn () => $this->create($overlap, 'overlap-same-capability'),
            'temporary_assignment_capability_overlap',
        );

        $this->create([...$overlap, 'capability_codes' => ['records.export']], 'overlap-disjoint-capability');
        $this->create([
            ...$first,
            'start_at' => $first['end_at'],
            'end_at' => $this->at('+8 days'),
        ], 'overlap-adjacent-window');

        $this->seedUnit(self::SECOND_UNIT_ID, 'TEMP-SECOND');
        $this->create([
            ...$overlap,
            'organization_unit_id' => self::SECOND_UNIT_ID,
        ], 'overlap-different-unit');

        $this->assertDatabaseCount('temporary_assignments', 4);
    }

    public function test_revoke_is_versioned_terminal_and_idempotent(): void
    {
        $created = $this->create($this->validInput([
            'start_at' => $this->at(),
            'end_at' => $this->at('+1 day'),
        ]), 'revoke-create');
        $id = $created['temporary_assignment']['id'];
        $reason = 'انتهاء الحاجة التشغيلية';
        $idempotency = $this->idempotency('temporary-revoke', ['reason' => $reason]);

        $revoked = $this->handler->revoke(
            $id,
            1,
            $reason,
            self::ACTOR_ID,
            self::CORRELATION_ID,
            $idempotency,
        );
        $this->assertTrue($revoked['changed']);
        $this->assertSame('revoked', $revoked['temporary_assignment']['state']);
        $this->assertSame(2, $revoked['temporary_assignment']['lock_version']);
        $this->assertSame($reason, $revoked['temporary_assignment']['revocation_reason']);

        $replay = $this->handler->revoke(
            $id,
            1,
            $reason,
            self::ACTOR_ID,
            self::CORRELATION_ID,
            $idempotency,
        );
        $this->assertFalse($replay['changed']);

        $this->assertIdempotencyConflict(
            fn () => $this->handler->revoke(
                $id,
                1,
                $reason,
                self::ACTOR_ID,
                self::CORRELATION_ID,
                [...$idempotency, 'request_hash' => str_repeat('e', 64)],
            ),
        );

        $this->assertDomainFailure(
            fn () => $this->handler->revoke(
                $id,
                1,
                'محاولة متأخرة',
                self::ACTOR_ID,
                self::CORRELATION_ID,
                $this->idempotency('temporary-revoke-stale', ['reason' => 'محاولة متأخرة']),
            ),
            'precondition_failed',
        );
        $this->assertDatabaseCount('outbox_events', 2);
    }

    public function test_active_fact_contract_expires_by_clock_before_bounded_persistence_cleanup(): void
    {
        $shortRead = $this->create($this->validInput([
            'start_at' => $this->at(),
            'end_at' => $this->at('+1 hour'),
            'capability_codes' => ['records.read'],
        ]), 'facts-short-read');
        $shortExport = $this->create($this->validInput([
            'start_at' => $this->at(),
            'end_at' => $this->at('+1 hour'),
            'capability_codes' => ['records.export'],
        ]), 'facts-short-export');
        $this->create($this->validInput([
            'start_at' => $this->at(),
            'end_at' => $this->at('+3 hours'),
            'capability_codes' => ['records.approve'],
        ]), 'facts-long');
        $this->create($this->validInput([
            'start_at' => $this->at('+1 hour'),
            'end_at' => $this->at('+4 hours'),
            'capability_codes' => ['records.assign'],
        ]), 'facts-future');
        $revoked = $this->create($this->validInput([
            'start_at' => $this->at(),
            'end_at' => $this->at('+4 hours'),
            'capability_codes' => ['records.delete'],
        ]), 'facts-revoked');
        $this->handler->revoke(
            $revoked['temporary_assignment']['id'],
            1,
            'سحب القدرة',
            self::ACTOR_ID,
            self::CORRELATION_ID,
            $this->idempotency('facts-revoke', ['reason' => 'سحب القدرة']),
        );

        $facts = $this->facts();
        $initialFacts = $facts->forPerson(self::PERSON_ID);
        $this->assertArrayNotHasKey('position_id', $initialFacts[0]);
        $this->assertSame(
            ['records.approve', 'records.export', 'records.read'],
            $this->factCapabilityCodes($initialFacts),
        );

        CarbonImmutable::setTestNow('2026-07-18T12:00:00.000Z');
        $this->assertSame(
            ['records.approve', 'records.assign'],
            $this->factCapabilityCodes($facts->forPerson(self::PERSON_ID)),
        );
        $this->assertDatabaseHas('temporary_assignments', [
            'id' => $shortRead['temporary_assignment']['id'],
            'state' => 'active',
        ]);
        $this->assertDatabaseHas('temporary_assignments', [
            'id' => $shortExport['temporary_assignment']['id'],
            'state' => 'active',
        ]);

        $expirer = new ExpireTemporaryAssignmentsHandler(new OrganizationOutbox, new TemporaryAssignmentEventFactory);
        $firstBatch = $expirer->handle(1, self::ACTOR_ID, self::CORRELATION_ID);
        $this->assertSame(1, $firstBatch['expired_count']);
        $this->assertTrue($firstBatch['has_more']);
        $secondBatch = $expirer->handle(1, self::ACTOR_ID, self::CORRELATION_ID);
        $this->assertSame(1, $secondBatch['expired_count']);
        $this->assertFalse($secondBatch['has_more']);
        $this->assertSame(2, DB::table('temporary_assignments')->where('state', 'expired')->count());
        $this->assertSame(2, DB::table('outbox_events')
            ->where('event_type', 'com.cluster.organization.temporaryassignmentexpired.v1')->count());
        $expiredEvent = json_decode((string) DB::table('outbox_events')
            ->where('event_type', 'com.cluster.organization.temporaryassignmentexpired.v1')
            ->value('cloud_event'), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame(self::ACTOR_ID, $expiredEvent['data']['access_context']['subject_id']);
        $this->assertSame(self::CLUSTER_ID, $expiredEvent['data']['access_context']['tenant_id']);

        $this->assertInvalid(fn () => $expirer->handle(0, self::ACTOR_ID, self::CORRELATION_ID), 'temporary_assignment_expiration_limit_invalid');
        $this->assertInvalid(fn () => $expirer->handle(501, self::ACTOR_ID, self::CORRELATION_ID), 'temporary_assignment_expiration_limit_invalid');
    }

    public function test_database_constraints_reject_invalid_windows_reasons_revocation_state_and_wildcards(): void
    {
        $invalidAssignments = [
            ['end_at' => $this->databaseAt('+1 day')],
            ['end_at' => $this->databaseAt('+92 days')],
            ['reason' => '   '],
            ['reason' => str_repeat('x', 2001)],
            ['state' => 'revoked'],
            [
                'state' => 'active',
                'revoked_at' => $this->databaseAt(),
                'revoked_by_user_id' => self::ACTOR_ID,
                'revocation_reason' => 'بيانات سحب متناقضة',
            ],
            ['lock_version' => 0],
        ];
        foreach ($invalidAssignments as $changes) {
            $this->assertQueryRejected(fn () => DB::table('temporary_assignments')->insert($this->rawAssignment($changes)));
        }

        $assignmentId = Str::uuid7()->toString();
        DB::table('temporary_assignments')->insert($this->rawAssignment(['id' => $assignmentId]));
        foreach (['records.*', 'records.?', 'records.%', 'records_read'] as $capabilityCode) {
            $this->assertQueryRejected(fn () => DB::table('temporary_assignment_capabilities')->insert([
                'temporary_assignment_id' => $assignmentId,
                'capability_code' => $capabilityCode,
            ]));
        }
        $this->assertDatabaseCount('temporary_assignment_capabilities', 0);
    }

    public function test_sqlite_window_trigger_compares_mixed_datetime_formats_chronologically(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Exercises the SQLite trigger implementation.');
        }

        $this->assertQueryRejected(fn () => DB::table('temporary_assignments')->insert($this->rawAssignment([
            'start_at' => $this->databaseAt('+1 day'),
            // Lexically greater because of "T", but chronologically one hour earlier.
            'end_at' => $this->at('+23 hours'),
        ])));
        $this->assertDatabaseCount('temporary_assignments', 0);
    }

    public function test_mysql_expiration_lock_clause_is_skip_locked_without_extra_row_locking(): void
    {
        $lock = new TemporaryAssignmentExpirationLock;

        $this->assertSame('for update skip locked', $lock->valueFor('mysql'));
        $this->assertSame('for update skip locked', $lock->valueFor('pgsql'));
        $this->assertTrue($lock->valueFor('sqlite'));
    }

    public function test_business_write_and_outbox_event_roll_back_together(): void
    {
        $eventId = Str::uuid7()->toString();
        $handler = new TemporaryAssignmentHandler(
            new OrganizationOutbox,
            new FixedTemporaryAssignmentEventFactory($eventId),
            $this->capabilityValidator,
        );
        $handler->create(
            Str::uuid7()->toString(),
            $this->validInput(['capability_codes' => ['records.read']]),
            self::ACTOR_ID,
            self::CORRELATION_ID,
            $this->idempotency('atomic-first', ['request' => 'first']),
        );

        try {
            $handler->create(
                Str::uuid7()->toString(),
                $this->validInput(['capability_codes' => ['records.export']]),
                self::ACTOR_ID,
                self::CORRELATION_ID,
                $this->idempotency('atomic-second', ['request' => 'second']),
            );
            $this->fail('A duplicate outbox event id must fail the transaction.');
        } catch (QueryException) {
            // The database uniqueness constraint is the intended fault injection.
        }

        $this->assertDatabaseCount('temporary_assignments', 1);
        $this->assertDatabaseCount('temporary_assignment_capabilities', 1);
        $this->assertDatabaseMissing('organization_idempotency_keys', ['operation' => 'atomic-second']);
        $this->assertDatabaseCount('outbox_events', 1);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function validInput(array $overrides = []): array
    {
        return [
            'person_id' => self::PERSON_ID,
            'organization_unit_id' => self::UNIT_ID,
            'capability_codes' => ['records.read', 'records.approve'],
            'start_at' => $this->at('+1 day'),
            'end_at' => $this->at('+7 days'),
            'reason' => 'تغطية تشغيلية مؤقتة',
            ...$overrides,
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function create(array $input, string $operation): array
    {
        return $this->handler->create(
            Str::uuid7()->toString(),
            $input,
            self::ACTOR_ID,
            self::CORRELATION_ID,
            $this->idempotency($operation, $input),
        );
    }

    /** @param array<string, mixed> $request @return array{principal_id: string, operation: string, key_hash: string, request_hash: string} */
    private function idempotency(string $operation, array $request): array
    {
        return [
            'principal_id' => self::ACTOR_ID,
            'operation' => $operation,
            'key_hash' => hash('sha256', $operation),
            'request_hash' => hash('sha256', json_encode($request, JSON_THROW_ON_ERROR)),
        ];
    }

    private function at(string $modifier = ''): string
    {
        $at = CarbonImmutable::now('UTC');
        if ($modifier !== '') {
            $at = $at->modify($modifier);
        }

        return $at->format('Y-m-d\TH:i:s.v\Z');
    }

    private function databaseAt(string $modifier = ''): string
    {
        $at = CarbonImmutable::now('UTC');
        if ($modifier !== '') {
            $at = $at->modify($modifier);
        }

        return $at->format('Y-m-d H:i:s.v');
    }

    private function assertInvalid(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail("Expected InvalidArgumentException: {$message}");
        } catch (InvalidArgumentException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }

    private function assertDomainFailure(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail("Expected DomainException: {$message}");
        } catch (DomainException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }

    private function assertIdempotencyConflict(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected TemporaryAssignmentIdempotencyConflict.');
        } catch (TemporaryAssignmentIdempotencyConflict $exception) {
            $this->assertSame('temporary_assignment_idempotency_conflict', $exception->getMessage());
        }
    }

    private function assertQueryRejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected the database constraint to reject the write.');
        } catch (QueryException) {
            // The database invariant is the assertion target.
        }
    }

    private function facts(): ListActiveTemporaryAssignmentFacts
    {
        return new DatabaseListActiveTemporaryAssignmentFacts($this->capabilityValidator);
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    private function rawAssignment(array $changes = []): array
    {
        return [
            'id' => Str::uuid7()->toString(),
            'person_id' => self::PERSON_ID,
            'organization_unit_id' => self::UNIT_ID,
            'start_at' => $this->databaseAt('+1 day'),
            'end_at' => $this->databaseAt('+2 days'),
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

    private function migrateTemporaryAssignments(): void
    {
        $migration = require dirname(__DIR__).'/Infrastructure/Persistence/Migrations/ZCreateOrganizationTemporaryAssignmentsTable.php';
        $migration->up();
    }

    /** @param list<array<string, mixed>> $facts @return list<string> */
    private function factCapabilityCodes(array $facts): array
    {
        $codes = [];
        foreach ($facts as $fact) {
            foreach ($fact['capability_codes'] as $code) {
                $codes[] = $code;
            }
        }
        sort($codes);

        return $codes;
    }

    private function seedOrganizationReferences(): void
    {
        DB::table('clusters')->insert([
            'id' => self::CLUSTER_ID,
            'code' => 'TEMP',
            'name_ar' => 'تجمع الاختبار',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('unit_types')->insert([
            'id' => self::UNIT_TYPE_ID,
            'code' => 'temporary_test_unit',
            'name_ar' => 'وحدة اختبار',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->seedUnit(self::UNIT_ID, 'TEMP-UNIT');
        DB::table('people')->insert([
            'id' => self::PERSON_ID,
            'employee_number' => 'TEMP-EMP-001',
            'display_name_ar' => 'موظف اختبار',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedUnit(string $unitId, string $code, string $status = 'active'): void
    {
        DB::table('organization_units')->insert([
            'id' => $unitId,
            'cluster_id' => self::CLUSTER_ID,
            'parent_id' => self::CLUSTER_ID,
            'parent_type' => 'cluster',
            'unit_type_id' => self::UNIT_TYPE_ID,
            'code' => $code,
            'name_ar' => 'الوحدة المستهدفة',
            'status' => $status,
            'path_cache' => '/'.$unitId,
            'depth' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

final class FixedTemporaryAssignmentEventFactory implements BuildTemporaryAssignmentEvent
{
    public function __construct(private readonly string $eventId) {}

    public function make(
        string $type,
        array $temporaryAssignment,
        string $subjectId,
        string $tenantId,
        string $correlationId,
    ): array {
        return [
            'specversion' => '1.0',
            'id' => $this->eventId,
            'source' => '/organization/temporary-assignments',
            'type' => $type,
            'subject' => '/temporary-assignments/'.$temporaryAssignment['id'],
            'time' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            'datacontenttype' => 'application/json',
            'correlationid' => $correlationId,
            'data' => ['classification' => 'internal'],
        ];
    }
}

final class MutableTemporaryAssignmentCapabilityValidator implements ValidateTemporaryAssignmentCapabilities
{
    /** @var array<string, true> */
    private array $active;

    private bool $fails = false;

    private ?string $advanceClockTo = null;

    /** @param list<string> $active */
    public function __construct(array $active)
    {
        $this->active = array_fill_keys($active, true);
    }

    public function allAreActive(array $capabilityCodes): bool
    {
        if ($this->advanceClockTo !== null) {
            CarbonImmutable::setTestNow($this->advanceClockTo);
            $this->advanceClockTo = null;
        }
        if ($this->fails) {
            throw new RuntimeException('The governed capability catalogue is unavailable.');
        }
        foreach ($capabilityCodes as $capabilityCode) {
            if (! isset($this->active[$capabilityCode])) {
                return false;
            }
        }

        return true;
    }

    public function deactivate(string $capabilityCode): void
    {
        unset($this->active[$capabilityCode]);
    }

    public function failValidation(): void
    {
        $this->fails = true;
    }

    public function recover(): void
    {
        $this->fails = false;
    }

    public function advanceClockTo(string $timestamp): void
    {
        $this->advanceClockTo = $timestamp;
    }
}
