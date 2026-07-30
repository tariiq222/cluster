<?php

declare(strict_types=1);

namespace Modules\Authorization\Tests;

use Database\Seeders\AuthorizationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Authorization\Features\Administration\Application\AuthorizationAdminService;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Shared\Contracts\RecordAuditEvent;
use Tests\TestCase;

/**
 * Task 1B Step 2 — `record_set` is explicitly removed from the
 * authorization-administration catalog. POST/PATCH requests that carry
 * `scope_type=record_set` must be rejected with the canonical 422
 * envelope before any row is written, before any audit event is emitted,
 * and before any `Idempotency-Key` is persisted.
 *
 * The narrowness of the guard is also pinned here: revoke/expire on a
 * historical `record_set` row must continue to succeed because the
 * transition request body never carries a `scope_type` field. The guard
 * fires only when the input or patch payload explicitly sets
 * `scope_type=record_set`.
 *
 * The audit double-throw contract is preserved: the validation guard
 * runs INSIDE the `DB::transaction(function () { ... })` `mutate()`
 * callback BEFORE the gateway create/update call, the guard throws the
 * existing `InvalidArgumentException('authorization_scope_type_not_catalogued')`,
 * and the outer transaction rolls back cleanly.
 */
#[CoversClass(AuthorizationAdminService::class)]
final class AuthorizationRoleAssignmentRecordSetFailClosedTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000b71';

    private const PRINCIPAL_ID = '018f6f7d-0c00-7000-8000-000000000b72';

    private const CLUSTER_ID = '018f6f7d-0c00-7000-8000-000000000b73';

    private const SYSTEM_ROLE_ID = '018f6f7d-0c00-7000-8000-000000000b74';

    private const SUBJECT_USER_ID = '018f6f7d-0c00-7000-8000-000000000b75';

    private const LEGACY_RECORD_SET_ID = '018f6f7d-0c00-7000-8000-000000000b76';

    private const LEGACY_RECORD_SET_SCOPE = '0197f0e0-0000-7000-8000-legacydeadbe';

    private const PRINCIPAL_CLUSTER_ASSIGNMENT_ID = '018f6f7d-0c00-7000-8000-000000000b77';

    private const PRINCIPAL_RECORDSET_ASSIGNMENT_ID = '018f6f7d-0c00-7000-8000-000000000b78';

    private CapturingSharedAuditRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogSeeder::class);
        $this->seedCluster();
        $this->seedPrincipalUser();
        $this->seedSubjectUser();
        $this->seedSystemRole();
        $this->seedPrincipalClusterAssignment();
        $this->seedPrincipalRecordSetAssignment();
        $this->seedLegacyRecordSetAssignment();

        $this->recorder = self::capturingRecorder();
        $this->app->instance(RecordAuditEvent::class, $this->recorder);
        $this->app->forgetInstance(AuthorizationAdminService::class);
    }

    public function test_create_assignment_with_record_set_returns_no_row_no_audit_no_idempotency(): void
    {
        $newScopeId = '0197f0e0-0000-7000-8000-deadbeefcafe';
        $input = [
            'resource_type' => 'role_assignment',
            'user_id' => self::SUBJECT_USER_ID,
            'role_id' => self::SYSTEM_ROLE_ID,
            'scope_type' => 'record_set',
            'scope_id' => $newScopeId,
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
        ];

        try {
            $this->service()->createAssignment($input, self::PRINCIPAL_ID, self::CORRELATION_ID, 'rs-create-key');
            $this->fail('Expected record_set to be rejected with InvalidArgumentException(authorization_scope_type_not_catalogued).');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('authorization_scope_type_not_catalogued', $exception->getMessage());
        }

        $this->assertSame(
            0,
            (int) DB::table('role_assignments')->where('scope_id', $newScopeId)->count(),
            'no role_assignments row may be inserted for the rejected scope_id',
        );

        $this->assertSame(
            0,
            count($this->recorder->events),
            'audit port must not be invoked for a record_set rejection',
        );

        $this->assertSame(
            0,
            (int) DB::table('authorization_idempotency_keys')->where('operation', 'create-role-assignments')->count(),
            'Idempotency-Key must not be persisted when the guard rejects scope_type=record_set',
        );
    }

    public function test_update_assignment_with_record_set_returns_no_row_no_audit_no_idempotency(): void
    {
        $newScopeId = '0197f0e0-0000-7000-8000-deadbeef0002';
        $input = [
            'scope_type' => 'record_set',
            'scope_id' => $newScopeId,
        ];

        try {
            $this->service()->updateAssignment(
                self::LEGACY_RECORD_SET_ID,
                $input,
                1,
                self::PRINCIPAL_ID,
                self::CORRELATION_ID,
                'rs-update-key',
            );
            $this->fail('Expected record_set to be rejected with InvalidArgumentException(authorization_scope_type_not_catalogued).');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('authorization_scope_type_not_catalogued', $exception->getMessage());
        }

        $row = DB::table('role_assignments')->where('id', self::LEGACY_RECORD_SET_ID)->first();
        $this->assertNotNull($row);
        $this->assertSame('record_set', (string) $row->scope_type);
        $this->assertSame(self::LEGACY_RECORD_SET_SCOPE, (string) $row->scope_id);
        $this->assertSame(1, (int) $row->lock_version);
        $this->assertSame('active', (string) $row->status);

        $this->assertSame(
            0,
            count($this->recorder->events),
            'audit port must not be invoked for a record_set rejection',
        );

        $this->assertSame(
            0,
            (int) DB::table('authorization_idempotency_keys')->where('operation', 'patch-role-assignments-'.self::LEGACY_RECORD_SET_ID)->count(),
            'Idempotency-Key must not be persisted when the guard rejects scope_type=record_set',
        );
    }

    public function test_revoke_assignment_on_legacy_record_set_row_succeeds(): void
    {
        $result = $this->service()->revokeAssignment(
            self::LEGACY_RECORD_SET_ID,
            1,
            self::PRINCIPAL_ID,
            self::CORRELATION_ID,
            'rs-revoke-key',
        );

        $this->assertSame('revoked', (string) ($result['entity']['status'] ?? ''));
        $this->assertSame('record_set', (string) ($result['entity']['scope_type'] ?? ''));
        $this->assertSame(self::LEGACY_RECORD_SET_ID, (string) $result['entity']['id']);

        $this->assertSame(1, count($this->recorder->events));
        $this->assertSame('authorization.assignment.revoked', $this->recorder->events[0]['action']);
    }

    public function test_expire_assignment_on_legacy_record_set_row_succeeds(): void
    {
        $result = $this->service()->expireAssignment(
            self::LEGACY_RECORD_SET_ID,
            1,
            self::PRINCIPAL_ID,
            self::CORRELATION_ID,
            'rs-expire-key',
        );

        $this->assertSame('expired', (string) ($result['entity']['status'] ?? ''));
        $this->assertSame('record_set', (string) ($result['entity']['scope_type'] ?? ''));
        $this->assertSame(self::LEGACY_RECORD_SET_ID, (string) $result['entity']['id']);

        $this->assertSame(1, count($this->recorder->events));
        $this->assertSame('authorization.assignment.expired', $this->recorder->events[0]['action']);
    }

    public function test_guard_executes_inside_mutate_callback_before_gateway_call(): void
    {
        $newScopeId = '0197f0e0-0000-7000-8000-deadbeef0003';
        $input = [
            'resource_type' => 'role_assignment',
            'user_id' => self::SUBJECT_USER_ID,
            'role_id' => self::SYSTEM_ROLE_ID,
            'scope_type' => 'record_set',
            'scope_id' => $newScopeId,
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
        ];

        // The audit port is rigged to throw on every record(). If the
        // record_set guard fires INSIDE the mutate callback BEFORE the
        // gateway call, the audit port is never invoked and the rolled
        // back surface is exactly what a guard-first rejection looks
        // like: a record_set InvalidArgumentException, zero new rows,
        // zero idempotency-keys, zero audit calls.
        $this->recorder->failure = new RuntimeException('audit_failure_simulated');

        try {
            $this->service()->createAssignment($input, self::PRINCIPAL_ID, self::CORRELATION_ID, 'rs-guard-order');
            $this->fail('Expected record_set rejection, got success.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('authorization_scope_type_not_catalogued', $exception->getMessage());
        }

        $this->assertSame(
            0,
            (int) DB::table('role_assignments')->where('scope_id', $newScopeId)->count(),
            'outer transaction must roll back: no row inserted',
        );

        $this->assertSame(
            0,
            (int) DB::table('authorization_idempotency_keys')->where('operation', 'create-role-assignments')->count(),
            'outer transaction must roll back: no idempotency-key row',
        );

        $this->assertSame(
            0,
            count($this->recorder->events),
            'audit port must not be invoked when the guard fires before the gateway call',
        );
    }

    private function service(): AuthorizationAdminService
    {
        return $this->app->make(AuthorizationAdminService::class);
    }

    private static function capturingRecorder(): CapturingSharedAuditRecorder
    {
        return new CapturingSharedAuditRecorder;
    }

    private function seedCluster(): void
    {
        if (DB::table('clusters')->where('id', self::CLUSTER_ID)->exists()) {
            return;
        }
        DB::table('clusters')->insert([
            'id' => self::CLUSTER_ID,
            'singleton_key' => 0,
            'code' => 'rs-cluster',
            'name_ar' => 'تجمع الإغلاق',
            'name_en' => 'Record-set fail-closed cluster',
            'status' => 'active',
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedSystemRole(): void
    {
        if (DB::table('roles')->where('id', self::SYSTEM_ROLE_ID)->exists()) {
            return;
        }
        DB::table('roles')->insert([
            'id' => self::SYSTEM_ROLE_ID,
            'code' => 'rs-fail-closed-role',
            'name_ar' => 'دور إغلاق السجل',
            'name_en' => 'Record-set fail-closed role',
            'role_type' => 'system',
            'status' => 'active',
            'is_system_role' => true,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedPrincipalUser(): void
    {
        if (DB::table('users')->where('id', self::PRINCIPAL_ID)->exists()) {
            return;
        }
        DB::table('users')->insert([
            'id' => self::PRINCIPAL_ID,
            'username' => 'rs-fail-closed-admin',
            'person_id' => null,
            'person_version' => null,
            'display_name_ar' => 'مسؤول إغلاق السجل',
            'display_name_en' => 'Record-set fail-closed admin',
            'status' => 'active',
            'must_change_password' => false,
            'password_version' => 1,
            'failed_login_count' => 0,
            'lockout_level' => 0,
            'locked_until' => null,
            'lock_version' => 1,
            'is_admin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedSubjectUser(): void
    {
        if (DB::table('users')->where('id', self::SUBJECT_USER_ID)->exists()) {
            return;
        }
        DB::table('users')->insert([
            'id' => self::SUBJECT_USER_ID,
            'username' => 'rs-fail-closed-subject',
            'person_id' => null,
            'person_version' => null,
            'display_name_ar' => 'موضوع إغلاق السجل',
            'display_name_en' => 'Record-set fail-closed subject',
            'status' => 'active',
            'must_change_password' => false,
            'password_version' => 1,
            'failed_login_count' => 0,
            'lockout_level' => 0,
            'locked_until' => null,
            'lock_version' => 1,
            'is_admin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedPrincipalClusterAssignment(): void
    {
        if (DB::table('role_assignments')->where('id', self::PRINCIPAL_CLUSTER_ASSIGNMENT_ID)->exists()) {
            return;
        }
        DB::table('role_assignments')->insert([
            'id' => self::PRINCIPAL_CLUSTER_ASSIGNMENT_ID,
            'user_id' => self::PRINCIPAL_ID,
            'role_id' => self::SYSTEM_ROLE_ID,
            'scope_type' => 'cluster',
            'scope_id' => self::CLUSTER_ID,
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => self::PRINCIPAL_ID,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedPrincipalRecordSetAssignment(): void
    {
        if (DB::table('role_assignments')->where('id', self::PRINCIPAL_RECORDSET_ASSIGNMENT_ID)->exists()) {
            return;
        }
        DB::table('role_assignments')->insert([
            'id' => self::PRINCIPAL_RECORDSET_ASSIGNMENT_ID,
            'user_id' => self::PRINCIPAL_ID,
            'role_id' => self::SYSTEM_ROLE_ID,
            'scope_type' => 'record_set',
            'scope_id' => self::LEGACY_RECORD_SET_SCOPE,
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => self::PRINCIPAL_ID,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedLegacyRecordSetAssignment(): void
    {
        if (DB::table('role_assignments')->where('id', self::LEGACY_RECORD_SET_ID)->exists()) {
            return;
        }
        DB::table('role_assignments')->insert([
            'id' => self::LEGACY_RECORD_SET_ID,
            'user_id' => self::SUBJECT_USER_ID,
            'role_id' => self::SYSTEM_ROLE_ID,
            'scope_type' => 'record_set',
            'scope_id' => self::LEGACY_RECORD_SET_SCOPE,
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => self::PRINCIPAL_ID,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
