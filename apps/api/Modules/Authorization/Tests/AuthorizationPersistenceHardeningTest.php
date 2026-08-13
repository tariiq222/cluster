<?php

namespace Modules\Authorization\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Modules\Authorization\Infrastructure\Persistence\DatabaseRecordSensitiveAccessEvent;
use Modules\Authorization\Infrastructure\Persistence\DatabaseResolveActiveFacilityScopesForUser;
use Tests\TestCase;

class AuthorizationPersistenceHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const ROLE_ID = '018f6f7d-0c00-7000-8000-000000000811';

    private const USER_ID = '018f6f7d-0c00-7000-8000-000000000813';

    private const OTHER_USER_ID = '018f6f7d-0c00-7000-8000-000000000814';

    private const SCOPE_ID = '018f6f7d-0c00-7000-8000-000000000818';

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('roles')) {
            (require dirname(__DIR__).'/Infrastructure/Persistence/Migrations/CreateAuthorizationRbacDataTables.php')->up();
            (require dirname(__DIR__).'/Infrastructure/Persistence/Migrations/W13AddAuthorizationScopeTypes.php')->up();
        }
        if (! Schema::hasTable('classification_policies')) {
            (require dirname(__DIR__).'/Infrastructure/Persistence/Migrations/CreateAuthorizationFieldAuditTables.php')->up();
        }
        if (! Schema::hasTable('access_decisions')) {
            (require dirname(__DIR__).'/Infrastructure/Persistence/Migrations/ZAddAuthorizationHttpTables.php')->up();
        }
        (require dirname(__DIR__).'/Infrastructure/Persistence/Migrations/W23HardenAuthorizationPersistence.php')->up();
    }

    protected function migrateDatabases(): void
    {
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        $this->artisan('migrate', [
            '--path' => 'Modules/Authorization/Infrastructure/Persistence/Migrations/ZAddAuthorizationHttpTables.php',
            '--force' => true,
        ]);
        foreach ([
            'CreateAuthorizationRbacDataTables.php',
            'CreateAuthorizationFieldAuditTables.php',
            'ZAddAuthorizationHttpTables.php',
            'W13AddAuthorizationScopeTypes.php',
        ] as $migration) {
            $this->artisan('migrate', [
                '--path' => 'Modules/Authorization/Infrastructure/Persistence/Migrations/'.$migration,
                '--force' => true,
            ]);
        }
        $migration = require dirname(__DIR__).'/Infrastructure/Persistence/Migrations/W23HardenAuthorizationPersistence.php';
        $migration->up();
    }

    public function test_sensitive_access_event_idempotency_tuple_is_unique_and_replays_return_false(): void
    {
        $indexes = array_values(array_filter(
            Schema::getIndexes('sensitive_access_events'),
            static fn (array $index): bool => $index['name'] === 'sensitive_access_events_idem_unique',
        ));

        $this->assertCount(1, $indexes);
        $this->assertTrue($indexes[0]['unique']);
        $this->assertSame(
            ['idempotency_key_hash', 'resource_type', 'resource_id', 'action'],
            $indexes[0]['columns'],
        );

        $event = $this->sensitiveAccessEvent();
        $recorder = new DatabaseRecordSensitiveAccessEvent;

        $this->assertTrue($recorder->record($event));
        $this->assertFalse($recorder->record($event));
        $this->assertDatabaseCount('sensitive_access_events', 1);

        try {
            DB::table('sensitive_access_events')->insert([
                ...$this->storedSensitiveAccessEvent($event),
                'id' => '018f6f7d-0c00-7000-8000-000000000830',
            ]);
            $this->fail('Expected the unique idempotency tuple to reject a forced duplicate insert.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', (string) $exception->getCode());
        }

        $this->assertDatabaseCount('sensitive_access_events', 1);
    }

    public function test_access_decisions_are_append_only(): void
    {
        $decision = $this->accessDecision();
        DB::table('access_decisions')->insert($decision);

        $this->assertQueryRejected(fn () => DB::table('access_decisions')
            ->where('id', $decision['id'])
            ->update(['decision' => 'deny']));
        $this->assertQueryRejected(fn () => DB::table('access_decisions')
            ->where('id', $decision['id'])
            ->delete());

        $this->assertDatabaseHas('access_decisions', [
            'id' => $decision['id'],
            'decision' => 'allow',
        ]);
    }

    public function test_role_assignment_overlap_is_partitioned_by_scope_type(): void
    {
        $this->seedRole();
        $this->insertRoleAssignment('018f6f7d-0c00-7000-8000-000000000819', 'facility');
        $this->insertRoleAssignment('018f6f7d-0c00-7000-8000-000000000820', 'unit');

        $this->assertQueryRejected(fn () => $this->insertRoleAssignment(
            '018f6f7d-0c00-7000-8000-000000000821',
            'facility',
        ));

        $this->assertDatabaseCount('role_assignments', 2);
    }

    public function test_facility_scope_resolution_rejects_invalid_timestamps_and_returns_distinct_ids(): void
    {
        $this->seedRole();
        $this->insertRoleAssignment(
            '018f6f7d-0c00-7000-8000-000000000819',
            'facility',
            '2026-07-20 10:00:00.000',
            '2026-07-22 10:00:00.000',
        );
        $this->insertRoleAssignment(
            '018f6f7d-0c00-7000-8000-000000000820',
            'facility',
            '2026-07-23 10:00:00.000',
            '2026-07-25 10:00:00.000',
        );

        $resolver = new DatabaseResolveActiveFacilityScopesForUser;

        $this->assertSame(
            [self::SCOPE_ID],
            $resolver->facilityScopeIds(self::USER_ID, '2026-07-24T10:00:00.000Z'),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('authorization_timestamp_invalid');
        $resolver->facilityScopeIds(self::USER_ID, 'not-a-date');
    }

    /**
     * @return array{idempotency_key: string, principal_id: string, source_ip: ?string, device_fingerprint_hash: ?string, correlation_id: ?string, classification_code: string, resource_type: string, resource_id: string, action: string, access_decision_id: ?string}
     */
    private function sensitiveAccessEvent(): array
    {
        return [
            'idempotency_key' => 'authorization-sensitive-event-replay',
            'principal_id' => self::USER_ID,
            'source_ip' => '127.0.0.1',
            'device_fingerprint_hash' => str_repeat('b', 64),
            'correlation_id' => '018f6f7d-0c00-7000-8000-000000000828',
            'classification_code' => 'confidential',
            'resource_type' => 'person',
            'resource_id' => '018f6f7d-0c00-7000-8000-000000000827',
            'action' => 'read',
            'access_decision_id' => null,
        ];
    }

    /**
     * @param  array{idempotency_key: string, principal_id: string, source_ip: ?string, device_fingerprint_hash: ?string, correlation_id: ?string, classification_code: string, resource_type: string, resource_id: string, action: string, access_decision_id: ?string}  $event
     * @return array<string, mixed>
     */
    private function storedSensitiveAccessEvent(array $event): array
    {
        return [
            'access_decision_id' => $event['access_decision_id'],
            'actor_user_id' => $event['principal_id'],
            'original_actor_user_id' => $event['principal_id'],
            'resource_type' => $event['resource_type'],
            'resource_id' => $event['resource_id'],
            'action' => $event['action'],
            'classification_code' => $event['classification_code'],
            'correlation_id' => $event['correlation_id'],
            'source_ip' => $event['source_ip'],
            'device_fingerprint_hash' => $event['device_fingerprint_hash'],
            'idempotency_key_hash' => hash('sha256', $event['idempotency_key']),
            'occurred_at' => now()->utc(),
            'recorded_at' => now()->utc(),
        ];
    }

    /** @return array<string, mixed> */
    private function accessDecision(): array
    {
        $now = now()->utc();

        return [
            'id' => '018f6f7d-0c00-7000-8000-000000000829',
            'decision' => 'allow',
            'action' => 'tasks.read',
            'resource_type' => 'task',
            'resource_id' => '018f6f7d-0c00-7000-8000-000000000827',
            'reason_codes' => json_encode(['capability_granted'], JSON_THROW_ON_ERROR),
            'policy_version' => 'test-v1',
            'facts_version' => 'test-v1',
            'authorization_trace_id' => '018f6f7d-0c00-7000-8000-000000000826',
            'evaluated_at' => $now,
            'correlation_id' => '018f6f7d-0c00-7000-8000-000000000828',
            'classification' => 'internal',
            'access_context' => json_encode(['user_id' => self::USER_ID], JSON_THROW_ON_ERROR),
            'actor_user_id' => self::USER_ID,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function seedRole(): void
    {
        DB::table('roles')->insert([
            'id' => self::ROLE_ID,
            'code' => 'facility_manager',
            'name_ar' => 'مدير المنشأة',
            'name_en' => 'Facility manager',
            'role_type' => 'administrative',
            'status' => 'active',
            'is_system_role' => false,
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);
    }

    private function insertRoleAssignment(
        string $id,
        string $scopeType,
        string $startAt = '2026-07-20 10:00:00.000',
        ?string $endAt = '2026-07-26 10:00:00.000',
    ): void {
        DB::table('role_assignments')->insert([
            'id' => $id,
            'user_id' => self::USER_ID,
            'role_id' => self::ROLE_ID,
            'scope_id' => self::SCOPE_ID,
            'scope_type' => $scopeType,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'status' => 'active',
            'granted_by_user_id' => self::OTHER_USER_ID,
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);
    }

    private function assertQueryRejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected the database invariant to reject the write.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
