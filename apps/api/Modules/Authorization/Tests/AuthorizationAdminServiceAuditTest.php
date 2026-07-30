<?php

declare(strict_types=1);

namespace Modules\Authorization\Tests;

use Database\Seeders\AuthorizationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Authorization\Features\Administration\Application\AuthorizationAdminService;
use RuntimeException;
use Shared\Contracts\RecordAuditEvent;
use Tests\TestCase;

/**
 * Task 4 — AuthorizationAdminService owns exactly one outer DB::transaction
 * per public mutation, calls the gateway first, and emits exactly one
 * audit event after mutation but before commit. If the audit port throws,
 * the outer transaction rolls back: no role/role_capability/role_assignment
 * rows from the throwing attempt are written, and the audit port is
 * invoked exactly once.
 *
 * The principal is intentionally seed-backed with a single super-admin role
 * assignment so the gateway's actor-scope and grant-authority checks pass
 * for the cluster scope used by every test. Each test exercises ONE
 * mutation, asserts the audit payload, then re-runs the same mutation with
 * a throwing recorder and asserts the database tables are unchanged from
 * the post-success state.
 */
final class AuthorizationAdminServiceAuditTest extends TestCase
{
    use RefreshDatabase;

    private const PRINCIPAL_ID = '018f6f7d-0c00-7000-8000-000000000951';

    private const SYSTEM_ROLE_ID = '018f6f7d-0c00-7000-8000-000000000952';

    private const EDIT_ROLE_ID = '018f6f7d-0c00-7000-8000-000000000953';

    private const ARCHIVE_ROLE_ID = '018f6f7d-0c00-7000-8000-000000000954';

    private const CLUSTER_ID = '018f6f7d-0c00-7000-8000-000000000955';

    private const SUBJECT_USER_ID = '018f6f7d-0c00-7000-8000-000000000956';

    private const ASSIGN_ROLE_ID = '018f6f7d-0c00-7000-8000-000000000957';

    private const UPDATE_ASSIGNMENT_ID = '018f6f7d-0c00-7000-8000-000000000958';

    private const REVOKE_ASSIGNMENT_ID = '018f6f7d-0c00-7000-8000-000000000959';

    private const EXPIRE_ASSIGNMENT_ID = '018f6f7d-0c00-7000-8000-00000000095a';

    private const CUSTOM_ROLE_REVOKE_ID = '018f6f7d-0c00-7000-8000-00000000095b';

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000a01';

    private const SCOPE_BRACKET_USER_ID = '018f6f7d-0c00-7000-8000-000000009a00';

    private const SCOPE_BRACKET_ASSIGNMENT_PREFIX = '018f6f7d-0c00-7000-8000-000000009a';

    private const UPDATE_ASSIGNEE_ID = '018f6f7d-0c00-7000-8000-0000000009d1';

    private const REVOKE_ASSIGNEE_ID = '018f6f7d-0c00-7000-8000-0000000009d2';

    private const EXPIRE_ASSIGNEE_ID = '018f6f7d-0c00-7000-8000-0000000009d3';

    private CapturingSharedAuditRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationCatalogSeeder::class);
        $this->seedCluster();
        $this->seedPrincipal();
        $this->seedSubjectUsers();
        $this->recorder = new CapturingSharedAuditRecorder();
        $this->app->instance(RecordAuditEvent::class, $this->recorder);
        $this->app->forgetInstance(AuthorizationAdminService::class);
    }

    private function service(): AuthorizationAdminService
    {
        return $this->app->make(AuthorizationAdminService::class);
    }

    public function test_create_role_writes_one_audit_event_and_rolls_back_when_audit_throws(): void
    {
        $input = [
            'resource_type' => 'role',
            'code' => 'audit-create-role',
            'name' => 'دور الإنشاء',
            'role_type' => 'custom',
        ];

        $result = $this->service()->createRole($input, self::PRINCIPAL_ID, self::CORRELATION_ID, 'create-role-key');

        $this->assertSame(1, count($this->recorder->calls), 'Audit must be invoked exactly once on success.');
        $event = $this->recorder->calls[0];
        $this->assertSame('authorization', $event['source_module']);
        $this->assertSame('authorization.role.created', $event['action']);
        $this->assertSame('com.cluster.authorization.rolecreated.v1', $event['event_type']);
        $this->assertSame('user', $event['actor_type']);
        $this->assertSame(self::PRINCIPAL_ID, $event['actor_id']);
        $this->assertSame('role', $event['subject_type']);
        $this->assertSame($result['entity']['id'], $event['subject_id']);
        $this->assertSame(self::CORRELATION_ID, $event['correlation_id']);
        $this->assertSame('succeeded', $event['outcome']);
        $this->assertNull($event['context']['before']);
        $this->assertIsArray($event['context']['after']);
        $this->assertSame($result['entity']['id'], $event['context']['after']['id']);
        $this->assertSame(1, $this->countRows('roles', ['code' => 'audit-create-role']));

        $this->recorder->failure = new RuntimeException('audit_failure_simulated');
        $callsBefore = count($this->recorder->calls);

        try {
            $this->service()->createRole([
                'resource_type' => 'role',
                'code' => 'audit-create-role-throw',
                'name' => 'دور الإنشاء فاشل',
                'role_type' => 'custom',
            ], self::PRINCIPAL_ID, self::CORRELATION_ID, 'create-role-key-throw');
            $this->fail('Expected audit failure to throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit_failure_simulated', $exception->getMessage());
        }

        $this->assertSame($callsBefore + 1, count($this->recorder->calls), 'Throwing recorder must be invoked once.');
        $this->assertSame(0, $this->countRows('roles', ['code' => 'audit-create-role-throw']), 'No new role rows after audit failure.');
        $this->assertSame(1, $this->countRows('roles', ['code' => 'audit-create-role']), 'Successfully committed role remains.');
    }

    public function test_edit_role_writes_one_audit_event_and_rolls_back_when_audit_throws(): void
    {
        $this->seedCustomRole(self::EDIT_ROLE_ID, 'audit-edit-role', 1);

        $result = $this->service()->editRole(self::EDIT_ROLE_ID, ['name_ar' => 'اسم معدّل'], 1, self::PRINCIPAL_ID, self::CORRELATION_ID);

        $this->assertSame(1, count($this->recorder->calls));
        $event = $this->recorder->calls[0];
        $this->assertSame('authorization.role.updated', $event['action']);
        $this->assertSame('com.cluster.authorization.roleupdated.v1', $event['event_type']);
        $this->assertSame(self::EDIT_ROLE_ID, $event['subject_id']);
        $this->assertIsArray($event['context']['before']);
        $this->assertSame('دور تدقيق', $event['context']['before']['name_ar']);
        $this->assertIsArray($event['context']['after']);
        $this->assertSame('اسم معدّل', $event['context']['after']['name_ar']);
        $this->assertSame(2, (int) $result['entity']['lock_version']);

        DB::table('roles')->where('id', self::EDIT_ROLE_ID)->update([
            'name_ar' => 'دور تدقيق',
            'lock_version' => 2,
            'updated_at' => now(),
        ]);
        $this->recorder->failure = new RuntimeException('audit_failure_simulated');
        $callsBefore = count($this->recorder->calls);

        try {
            $this->service()->editRole(self::EDIT_ROLE_ID, ['name_ar' => 'اسم ثانٍ'], 2, self::PRINCIPAL_ID, self::CORRELATION_ID);
            $this->fail('Expected audit failure to throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit_failure_simulated', $exception->getMessage());
        }

        $this->assertSame($callsBefore + 1, count($this->recorder->calls));
        $this->assertSame('دور تدقيق', (string) DB::table('roles')->where('id', self::EDIT_ROLE_ID)->value('name_ar'));
        $this->assertSame(2, (int) DB::table('roles')->where('id', self::EDIT_ROLE_ID)->value('lock_version'));
    }

    public function test_archive_role_writes_one_audit_event_and_rolls_back_when_audit_throws(): void
    {
        $this->seedCustomRole(self::ARCHIVE_ROLE_ID, 'audit-archive-role', 1);

        $result = $this->service()->archiveRole(self::ARCHIVE_ROLE_ID, 1, self::PRINCIPAL_ID, self::CORRELATION_ID);

        $this->assertSame(1, count($this->recorder->calls));
        $event = $this->recorder->calls[0];
        $this->assertSame('authorization.role.archived', $event['action']);
        $this->assertSame('com.cluster.authorization.rolearchived.v1', $event['event_type']);
        $this->assertSame(self::ARCHIVE_ROLE_ID, $event['subject_id']);
        $this->assertSame('active', $event['context']['before']['status']);
        $this->assertSame('archived', $event['context']['after']['status']);
        $this->assertSame(2, (int) $result['entity']['lock_version']);

        DB::table('roles')->where('id', self::ARCHIVE_ROLE_ID)->update([
            'status' => 'active',
            'lock_version' => 2,
            'updated_at' => now(),
        ]);
        $this->recorder->failure = new RuntimeException('audit_failure_simulated');
        $callsBefore = count($this->recorder->calls);

        try {
            $this->service()->archiveRole(self::ARCHIVE_ROLE_ID, 2, self::PRINCIPAL_ID, self::CORRELATION_ID);
            $this->fail('Expected audit failure to throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit_failure_simulated', $exception->getMessage());
        }

        $this->assertSame($callsBefore + 1, count($this->recorder->calls));
        $this->assertSame('active', (string) DB::table('roles')->where('id', self::ARCHIVE_ROLE_ID)->value('status'));
        $this->assertSame(2, (int) DB::table('roles')->where('id', self::ARCHIVE_ROLE_ID)->value('lock_version'));
    }

    public function test_clone_role_writes_one_audit_event_and_rolls_back_when_audit_throws(): void
    {
        $this->seedSystemRole(self::SYSTEM_ROLE_ID);
        $this->seedRoleCapability(self::SYSTEM_ROLE_ID, 'work_record.read');

        $result = $this->service()->cloneRole(self::SYSTEM_ROLE_ID, 1, [], self::PRINCIPAL_ID, self::CORRELATION_ID);

        $this->assertSame(1, count($this->recorder->calls));
        $event = $this->recorder->calls[0];
        $this->assertSame('authorization.role.cloned', $event['action']);
        $this->assertSame('com.cluster.authorization.rolecloned.v1', $event['event_type']);
        $this->assertSame($result['entity']['id'], $event['subject_id']);
        $this->assertNotSame(self::SYSTEM_ROLE_ID, $event['subject_id']);
        $this->assertNull($event['context']['before']);
        $this->assertSame(self::SYSTEM_ROLE_ID, $event['context']['source']['role_id']);
        $this->assertSame($result['entity']['id'], $event['context']['after']['id']);
        $this->assertSame([(string) DB::table('capabilities')->where('capability_code', 'work_record.read')->value('id')], $event['context']['after']['capability_ids']);
        $this->assertSame(1, $this->countRows('roles', ['id' => $result['entity']['id']]));

        $secondCloneSourceId = '018f6f7d-0c00-7000-8000-000000000bb2';
        $secondCloneSourceCode = 'second-'.bin2hex(random_bytes(8));
        DB::table('roles')->insert([
            'id' => $secondCloneSourceId,
            'code' => $secondCloneSourceCode,
            'name_ar' => 'دور نظامي ثانٍ',
            'name_en' => 'Second system role',
            'role_type' => 'system',
            'status' => 'active',
            'is_system_role' => true,
            'created_at' => now(),
            'updated_at' => now(),
            'lock_version' => 1,
        ]);
        $workRecordReadId = (string) DB::table('capabilities')->where('capability_code', 'work_record.read')->value('id');
        DB::table('role_capabilities')->insert([
            'role_id' => $secondCloneSourceId,
            'capability_id' => $workRecordReadId,
            'effect' => 'allow',
            'created_at' => now(),
            'updated_at' => now(),
            'lock_version' => 1,
        ]);
        $this->seedRoleScopeBracket($secondCloneSourceId);

        $rolesBefore = (int) DB::table('roles')->count();
        $this->recorder->failure = new RuntimeException('audit_failure_simulated');
        $callsBefore = count($this->recorder->calls);

        try {
            $this->service()->cloneRole($secondCloneSourceId, 1, [], self::PRINCIPAL_ID, self::CORRELATION_ID);
            $this->fail('Expected audit failure to throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit_failure_simulated', $exception->getMessage());
        }

        $this->assertSame($callsBefore + 1, count($this->recorder->calls));
        $this->assertSame($rolesBefore, (int) DB::table('roles')->count(), 'No new role rows after audit failure.');
        $this->assertSame(1, $this->countRows('roles', ['id' => $result['entity']['id']]), 'Successfully committed clone row remains.');
        $this->assertSame(1, $this->countRows('roles', ['id' => $secondCloneSourceId]), 'Source role remains.');
    }

    public function test_create_assignment_writes_one_audit_event_and_rolls_back_when_audit_throws(): void
    {
        $this->seedCustomRole(self::ASSIGN_ROLE_ID, 'audit-create-assignment', 1);
        $this->seedRoleCapability(self::ASSIGN_ROLE_ID, 'work_record.read');

        $input = [
            'resource_type' => 'role_assignment',
            'user_id' => self::SUBJECT_USER_ID,
            'role_id' => self::ASSIGN_ROLE_ID,
            'scope_type' => 'cluster',
            'scope_id' => self::CLUSTER_ID,
            'start_at' => '2026-07-01T00:00:00.000Z',
        ];

        $result = $this->service()->createAssignment($input, self::PRINCIPAL_ID, self::CORRELATION_ID, 'create-assignment-key');

        $this->assertSame(1, count($this->recorder->calls));
        $event = $this->recorder->calls[0];
        $this->assertSame('authorization.assignment.created', $event['action']);
        $this->assertSame('com.cluster.authorization.assignmentcreated.v1', $event['event_type']);
        $this->assertSame('role_assignment', $event['subject_type']);
        $this->assertSame($result['entity']['id'], $event['subject_id']);
        $this->assertNull($event['context']['before']);
        $this->assertIsArray($event['context']['after']);
        $this->assertSame(1, $this->countRows('role_assignments', ['id' => $result['entity']['id']]));

        $createdAssignmentId = $result['entity']['id'];
        $this->recorder->failure = new RuntimeException('audit_failure_simulated');
        $callsBefore = count($this->recorder->calls);
        $throwSubject = '018f6f7d-0c00-7000-8000-0000000009aa';
        $this->seedUser($throwSubject, 'throw-subject-audit', 'موضوع فاشل', 'Throw subject');

        try {
            $this->service()->createAssignment([
                'resource_type' => 'role_assignment',
                'user_id' => $throwSubject,
                'role_id' => self::ASSIGN_ROLE_ID,
                'scope_type' => 'cluster',
                'scope_id' => self::CLUSTER_ID,
                'start_at' => '2026-07-01T00:00:00.000Z',
            ], self::PRINCIPAL_ID, self::CORRELATION_ID, 'create-assignment-key-throw');
            $this->fail('Expected audit failure to throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit_failure_simulated', $exception->getMessage());
        }

        $this->assertSame($callsBefore + 1, count($this->recorder->calls));
        $this->assertSame(0, $this->countRows('role_assignments', ['user_id' => $throwSubject]), 'No new assignment rows after audit failure.');
        $this->assertSame(1, $this->countRows('role_assignments', ['id' => $createdAssignmentId]), 'Successfully committed assignment remains.');
    }

    public function test_update_assignment_writes_one_audit_event_and_rolls_back_when_audit_throws(): void
    {
        $newEndAt = '2027-01-01T00:00:00.000Z';

        $result = $this->service()->updateAssignment(self::UPDATE_ASSIGNMENT_ID, ['end_at' => $newEndAt], 1, self::PRINCIPAL_ID, self::CORRELATION_ID);

        $this->assertSame(1, count($this->recorder->calls));
        $event = $this->recorder->calls[0];
        $this->assertSame('authorization.assignment.updated', $event['action']);
        $this->assertSame('com.cluster.authorization.assignmentupdated.v1', $event['event_type']);
        $this->assertSame(self::UPDATE_ASSIGNMENT_ID, $event['subject_id']);
        $this->assertNull($event['context']['before']['end_at']);
        $this->assertNotNull($event['context']['after']['end_at']);
        $this->assertSame(2, (int) $result['entity']['lock_version']);

        DB::table('role_assignments')->where('id', self::UPDATE_ASSIGNMENT_ID)->update(['end_at' => null, 'lock_version' => 2]);
        $this->recorder->failure = new RuntimeException('audit_failure_simulated');
        $callsBefore = count($this->recorder->calls);

        try {
            $this->service()->updateAssignment(self::UPDATE_ASSIGNMENT_ID, ['end_at' => $newEndAt], 2, self::PRINCIPAL_ID, self::CORRELATION_ID);
            $this->fail('Expected audit failure to throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit_failure_simulated', $exception->getMessage());
        }

        $this->assertSame($callsBefore + 1, count($this->recorder->calls));
        $this->assertNull(DB::table('role_assignments')->where('id', self::UPDATE_ASSIGNMENT_ID)->value('end_at'));
        $this->assertSame(2, (int) DB::table('role_assignments')->where('id', self::UPDATE_ASSIGNMENT_ID)->value('lock_version'));
    }

    public function test_revoke_assignment_writes_one_audit_event_and_rolls_back_when_audit_throws(): void
    {
        $result = $this->service()->revokeAssignment(self::REVOKE_ASSIGNMENT_ID, 1, self::PRINCIPAL_ID, self::CORRELATION_ID);

        $this->assertSame(1, count($this->recorder->calls));
        $event = $this->recorder->calls[0];
        $this->assertSame('authorization.assignment.revoked', $event['action']);
        $this->assertSame('com.cluster.authorization.assignmentrevoked.v1', $event['event_type']);
        $this->assertSame(self::REVOKE_ASSIGNMENT_ID, $event['subject_id']);
        $this->assertSame('active', $event['context']['before']['status']);
        $this->assertSame('revoked', $event['context']['after']['status']);
        $this->assertSame(2, (int) $result['entity']['lock_version']);

        DB::table('role_assignments')->where('id', self::REVOKE_ASSIGNMENT_ID)->update(['status' => 'active', 'lock_version' => 2]);
        $this->recorder->failure = new RuntimeException('audit_failure_simulated');
        $callsBefore = count($this->recorder->calls);

        try {
            $this->service()->revokeAssignment(self::REVOKE_ASSIGNMENT_ID, 2, self::PRINCIPAL_ID, self::CORRELATION_ID);
            $this->fail('Expected audit failure to throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit_failure_simulated', $exception->getMessage());
        }

        $this->assertSame($callsBefore + 1, count($this->recorder->calls));
        $this->assertSame('active', (string) DB::table('role_assignments')->where('id', self::REVOKE_ASSIGNMENT_ID)->value('status'));
        $this->assertSame(2, (int) DB::table('role_assignments')->where('id', self::REVOKE_ASSIGNMENT_ID)->value('lock_version'));
    }

    public function test_expire_assignment_writes_one_audit_event_and_rolls_back_when_audit_throws(): void
    {
        $result = $this->service()->expireAssignment(self::EXPIRE_ASSIGNMENT_ID, 1, self::PRINCIPAL_ID, self::CORRELATION_ID);

        $this->assertSame(1, count($this->recorder->calls));
        $event = $this->recorder->calls[0];
        $this->assertSame('authorization.assignment.expired', $event['action']);
        $this->assertSame('com.cluster.authorization.assignmentexpired.v1', $event['event_type']);
        $this->assertSame(self::EXPIRE_ASSIGNMENT_ID, $event['subject_id']);
        $this->assertSame('active', $event['context']['before']['status']);
        $this->assertSame('expired', $event['context']['after']['status']);
        $this->assertSame(2, (int) $result['entity']['lock_version']);

        DB::table('role_assignments')->where('id', self::EXPIRE_ASSIGNMENT_ID)->update(['status' => 'active', 'lock_version' => 2]);
        $this->recorder->failure = new RuntimeException('audit_failure_simulated');
        $callsBefore = count($this->recorder->calls);

        try {
            $this->service()->expireAssignment(self::EXPIRE_ASSIGNMENT_ID, 2, self::PRINCIPAL_ID, self::CORRELATION_ID);
            $this->fail('Expected audit failure to throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit_failure_simulated', $exception->getMessage());
        }

        $this->assertSame($callsBefore + 1, count($this->recorder->calls));
        $this->assertSame('active', (string) DB::table('role_assignments')->where('id', self::EXPIRE_ASSIGNMENT_ID)->value('status'));
        $this->assertSame(2, (int) DB::table('role_assignments')->where('id', self::EXPIRE_ASSIGNMENT_ID)->value('lock_version'));
    }

    public function test_revoke_role_capability_writes_one_audit_event_and_rolls_back_when_audit_throws(): void
    {
        $this->seedCustomRole(self::CUSTOM_ROLE_REVOKE_ID, 'audit-revoke-capability', 1);
        $this->seedRoleCapability(self::CUSTOM_ROLE_REVOKE_ID, 'work_record.read');
        $capabilityId = (string) DB::table('capabilities')->where('capability_code', 'work_record.read')->value('id');

        $result = $this->service()->revokeRoleCapability(self::CUSTOM_ROLE_REVOKE_ID.':'.$capabilityId, 1, self::PRINCIPAL_ID, self::CORRELATION_ID);

        $this->assertSame(1, count($this->recorder->calls));
        $event = $this->recorder->calls[0];
        $this->assertSame('authorization.role_capability.revoked', $event['action']);
        $this->assertSame('com.cluster.authorization.rolecapabilityrevoked.v1', $event['event_type']);
        $this->assertNull($event['subject_id']);
        $this->assertSame(self::CUSTOM_ROLE_REVOKE_ID, $event['context']['role_id']);
        $this->assertSame($capabilityId, $event['context']['capability_id']);
        $this->assertSame('allow', $event['context']['before']['effect']);
        $this->assertSame('revoked', $event['context']['after']['status']);
        $this->assertSame(0, $this->countRows('role_capabilities', [
            'role_id' => self::CUSTOM_ROLE_REVOKE_ID,
            'capability_id' => $capabilityId,
        ]));

        $this->seedRoleCapability(self::CUSTOM_ROLE_REVOKE_ID, 'work_record.read');
        $rowsBefore = $this->countRows('role_capabilities', [
            'role_id' => self::CUSTOM_ROLE_REVOKE_ID,
            'capability_id' => $capabilityId,
        ]);
        $this->recorder->failure = new RuntimeException('audit_failure_simulated');
        $callsBefore = count($this->recorder->calls);

        try {
            $this->service()->revokeRoleCapability(self::CUSTOM_ROLE_REVOKE_ID.':'.$capabilityId, 1, self::PRINCIPAL_ID, self::CORRELATION_ID);
            $this->fail('Expected audit failure to throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit_failure_simulated', $exception->getMessage());
        }

        $this->assertSame($callsBefore + 1, count($this->recorder->calls));
        $this->assertSame($rowsBefore, $this->countRows('role_capabilities', [
            'role_id' => self::CUSTOM_ROLE_REVOKE_ID,
            'capability_id' => $capabilityId,
        ]), 'No new role_capability rows after audit failure.');
    }

    public function test_clone_role_does_not_emit_audit_when_gateway_precondition_fails(): void
    {
        $this->seedSystemRole(self::SYSTEM_ROLE_ID);
        $callsBefore = count($this->recorder->calls);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('authorization_precondition_failed');

        try {
            $this->service()->cloneRole(self::SYSTEM_ROLE_ID, 99, [], self::PRINCIPAL_ID, self::CORRELATION_ID);
        } finally {
            $this->assertSame(1, (int) DB::table('roles')->where('id', self::SYSTEM_ROLE_ID)->count());
            $this->assertSame($callsBefore, count($this->recorder->calls), 'Precondition failure must not invoke the audit port.');
        }
    }

    public function test_create_role_replays_idempotently_without_repeating_the_mutation_or_audit(): void
    {
        $input = [
            'resource_type' => 'role',
            'code' => 'audit-idempotent-create-role',
            'name' => 'دور idempotent',
            'role_type' => 'custom',
        ];

        $first = $this->service()->createRole($input, self::PRINCIPAL_ID, self::CORRELATION_ID, 'idempotent-create-role');
        $second = $this->service()->createRole($input, self::PRINCIPAL_ID, self::CORRELATION_ID, 'idempotent-create-role');

        $this->assertSame($first['entity']['id'], $second['entity']['id']);
        $this->assertSame(1, $this->countRows('roles', ['code' => 'audit-idempotent-create-role']));
        $this->assertCount(1, $this->recorder->calls);
    }

    public function test_real_shared_audit_adapter_reuses_the_service_transaction_without_savepoints(): void
    {
        $this->app->forgetInstance(RecordAuditEvent::class);
        $this->app->forgetInstance(AuthorizationAdminService::class);
        $savepoints = [];
        DB::listen(function (object $query) use (&$savepoints): void {
            if (preg_match('/\A(?:SAVEPOINT|RELEASE SAVEPOINT|ROLLBACK TO SAVEPOINT)\b/i', trim((string) $query->sql)) === 1) {
                $savepoints[] = $query->sql;
            }
        });

        $result = $this->service()->createRole([
            'resource_type' => 'role',
            'code' => 'audit-real-adapter-no-savepoint',
            'name' => 'تدقيق فعلي',
            'role_type' => 'custom',
        ], self::PRINCIPAL_ID, self::CORRELATION_ID);

        $this->assertSame([], $savepoints);
        $this->assertSame(1, $this->countRows('audit_events', ['subject_id' => $result['entity']['id']]));
    }

    public function test_audit_emit_does_not_use_savepoints_inside_outer_transaction(): void
    {
        $this->seedCustomRole(self::EDIT_ROLE_ID, 'audit-no-savepoints', 1);
        $savepoints = [];
        DB::listen(function (object $query) use (&$savepoints): void {
            if (preg_match('/\A(?:SAVEPOINT|RELEASE SAVEPOINT|ROLLBACK TO SAVEPOINT)\b/i', trim((string) $query->sql)) === 1) {
                $savepoints[] = $query->sql;
            }
        });

        $this->service()->editRole(self::EDIT_ROLE_ID, ['name_ar' => 'بدون savepoint'], 1, self::PRINCIPAL_ID, self::CORRELATION_ID);

        $this->assertSame([], $savepoints, 'Service must own exactly one outer transaction, no savepoints.');
    }

    /** @param array<string, mixed> $where */
    private function countRows(string $table, array $where = []): int
    {
        $q = DB::table($table);
        foreach ($where as $column => $value) {
            $q->where($column, $value);
        }

        return (int) $q->count();
    }

    private function seedCluster(): void
    {
        DB::table('clusters')->insert([
            'id' => self::CLUSTER_ID,
            'singleton_key' => 1,
            'code' => 'audit-cluster',
            'name_ar' => 'تجمع التدقيق',
            'name_en' => 'Audit cluster',
            'status' => 'active',
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedPrincipal(): void
    {
        $this->seedUser(self::PRINCIPAL_ID, 'principal.audit', 'مدير النظام', 'Platform admin');
        $roleId = '018f6f7d-0c00-7000-8000-000000008000';
        DB::table('roles')->insert([
            'id' => $roleId,
            'code' => 'audit.platform.admin',
            'name_ar' => 'مسؤول التدقيق',
            'name_en' => 'Audit platform admin',
            'role_type' => 'system',
            'status' => 'active',
            'is_system_role' => true,
            'created_at' => now(),
            'updated_at' => now(),
            'lock_version' => 1,
        ]);
        $capabilityCodes = [
            'authorization.assignment.manage',
            'work_record.read',
        ];
        foreach ($capabilityCodes as $code) {
            $capabilityId = (string) DB::table('capabilities')->where('capability_code', $code)->value('id');
            DB::table('role_capabilities')->insert([
                'role_id' => $roleId,
                'capability_id' => $capabilityId,
                'effect' => 'allow',
                'created_at' => now(),
                'updated_at' => now(),
                'lock_version' => 1,
            ]);
        }
        DB::table('role_assignments')->insert([
            'id' => '018f6f7d-0c00-7000-8000-000000008001',
            'user_id' => self::PRINCIPAL_ID,
            'role_id' => $roleId,
            'scope_type' => 'cluster',
            'scope_id' => self::CLUSTER_ID,
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => self::PRINCIPAL_ID,
            'created_at' => now(),
            'updated_at' => now(),
            'lock_version' => 1,
        ]);
    }

    private function seedSubjectUsers(): void
    {
        $this->seedUser(self::SUBJECT_USER_ID, 'subject.audit', 'موضوع', 'Subject');
        $this->seedUser(self::UPDATE_ASSIGNEE_ID, 'assignee.update', 'محال', 'Update assignee');
        $this->seedUser(self::REVOKE_ASSIGNEE_ID, 'assignee.revoke', 'محال', 'Revoke assignee');
        $this->seedUser(self::EXPIRE_ASSIGNEE_ID, 'assignee.expire', 'محال', 'Expire assignee');
        if (! DB::table('roles')->where('id', self::ASSIGN_ROLE_ID)->exists()) {
            $this->seedCustomRole(self::ASSIGN_ROLE_ID, 'audit-assignee-role', 1);
        }
        $this->seedAssignmentRow(self::UPDATE_ASSIGNMENT_ID, self::ASSIGN_ROLE_ID, self::UPDATE_ASSIGNEE_ID);
        $this->seedAssignmentRow(self::REVOKE_ASSIGNMENT_ID, self::ASSIGN_ROLE_ID, self::REVOKE_ASSIGNEE_ID);
        $this->seedAssignmentRow(self::EXPIRE_ASSIGNMENT_ID, self::ASSIGN_ROLE_ID, self::EXPIRE_ASSIGNEE_ID);
    }

    private function seedUser(string $userId, string $username = 'user', string $nameAr = 'مستخدم', string $nameEn = 'User'): void
    {
        if (DB::table('users')->where('id', $userId)->exists()) {
            return;
        }
        DB::table('users')->insert([
            'id' => $userId,
            'username' => $username,
            'person_id' => null,
            'person_version' => null,
            'display_name_ar' => $nameAr,
            'display_name_en' => $nameEn,
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

    private function seedCustomRole(string $roleId, string $code, int $lockVersion): void
    {
        if (DB::table('roles')->where('id', $roleId)->exists()) {
            return;
        }
        DB::table('roles')->insert([
            'id' => $roleId,
            'code' => $code,
            'name_ar' => 'دور تدقيق',
            'name_en' => 'Audit role',
            'role_type' => 'custom',
            'status' => 'active',
            'is_system_role' => false,
            'created_at' => now(),
            'updated_at' => now(),
            'lock_version' => $lockVersion,
        ]);
        $this->seedRoleScopeBracket($roleId);
    }

    private function seedSystemRole(string $roleId): void
    {
        if (DB::table('roles')->where('id', $roleId)->exists()) {
            return;
        }
        DB::table('roles')->insert([
            'id' => $roleId,
            'code' => 'system-clone-source',
            'name_ar' => 'دور نظامي',
            'name_en' => 'System role',
            'role_type' => 'system',
            'status' => 'active',
            'is_system_role' => true,
            'created_at' => now(),
            'updated_at' => now(),
            'lock_version' => 1,
        ]);
        $this->seedRoleScopeBracket($roleId);
    }

    private function seedRoleScopeBracket(string $roleId): void
    {
        if (DB::table('role_assignments')->where('role_id', $roleId)->where('scope_id', self::CLUSTER_ID)->exists()) {
            return;
        }
        $this->seedUser(self::SCOPE_BRACKET_USER_ID);
        DB::table('role_assignments')->insert([
            'id' => self::SCOPE_BRACKET_ASSIGNMENT_PREFIX.bin2hex(random_bytes(2)),
            'user_id' => self::SCOPE_BRACKET_USER_ID,
            'role_id' => $roleId,
            'scope_type' => 'cluster',
            'scope_id' => self::CLUSTER_ID,
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => self::PRINCIPAL_ID,
            'created_at' => now(),
            'updated_at' => now(),
            'lock_version' => 1,
        ]);
    }

    private function seedRoleCapability(string $roleId, string $capabilityCode): void
    {
        $capabilityId = (string) DB::table('capabilities')->where('capability_code', $capabilityCode)->value('id');
        DB::table('role_capabilities')->insert([
            'role_id' => $roleId,
            'capability_id' => $capabilityId,
            'effect' => 'allow',
            'created_at' => now(),
            'updated_at' => now(),
            'lock_version' => 1,
        ]);
    }

    private function seedAssignmentRow(string $assignmentId, string $roleId, string $userId): void
    {
        DB::table('role_assignments')->insert([
            'id' => $assignmentId,
            'user_id' => $userId,
            'role_id' => $roleId,
            'scope_type' => 'cluster',
            'scope_id' => self::CLUSTER_ID,
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => self::PRINCIPAL_ID,
            'created_at' => now(),
            'updated_at' => now(),
            'lock_version' => 1,
        ]);
    }
}

/**
 * Captures every Shared\Contracts\RecordAuditEvent::record() payload.
 * Mutating the failure to a Throwable causes the next record() call to
 * throw after capture, forcing the service to roll back the outer
 * DB::transaction.
 */
final class CapturingSharedAuditRecorder implements RecordAuditEvent
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public ?\Throwable $failure = null;

    public function record(array $event): void
    {
        $this->calls[] = $event;

        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
