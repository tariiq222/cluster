<?php

namespace Modules\Authorization\Tests;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Authorization\Infrastructure\RbacAbacDecideAccess;
use Tests\TestCase;

class RbacAbacDecideAccessTest extends TestCase
{
    use RefreshDatabase;

    private const ROLE_ID = '018f6f7d-0c00-7000-8000-000000000901';

    private const CAPABILITY_ID = '018f6f7d-0c00-7000-8000-000000000902';

    private const USER_ID = '018f6f7d-0c00-7000-8000-000000000903';

    private const GRANTED_BY_USER_ID = '018f6f7d-0c00-7000-8000-000000000904';

    private const DELEGATION_ID = '018f6f7d-0c00-7000-8000-000000000908';

    private const DELEGATE_USER_ID = '018f6f7d-0c00-7000-8000-000000000909';

    private const NON_DELEGATE_USER_ID = '018f6f7d-0c00-7000-8000-000000000910';

    private const ORGANIZATION_UNIT_A = '018f6f7d-0c00-7000-8000-000000000905';

    private const ORGANIZATION_UNIT_B = '018f6f7d-0c00-7000-8000-000000000906';

    private const SUPERVISORY_RELATIONSHIP_ID = '018f6f7d-0c00-7000-8000-000000000911';

    private const RELATIONSHIP_CAPABILITY_ID = '018f6f7d-0c00-7000-8000-000000000912';

    private const RELATIONSHIP_CLUSTER_ID = '018f6f7d-0c00-7000-8000-000000000913';

    private const RELATIONSHIP_UNIT_TYPE_ID = '018f6f7d-0c00-7000-8000-000000000914';

    private const EXPLICIT_DENY_ID = '018f6f7d-0c00-7000-8000-000000000915';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-19 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function migrateDatabases(): void
    {
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        $this->artisan('migrate', [
            '--path' => 'Modules/Authorization/Infrastructure/Persistence/Migrations/CreateAuthorizationRbacDataTables.php',
            '--force' => true,
        ]);
        $this->artisan('migrate', [
            '--path' => 'Modules/Authorization/Infrastructure/Persistence/Migrations/CreateAuthorizationExplicitDenyTables.php',
            '--force' => true,
        ]);
    }

    public function test_active_role_capability_with_matching_scope_and_classification_allows_access(): void
    {
        $this->seedAllowingRole(scopeId: self::ORGANIZATION_UNIT_A, sensitivity: 'sensitive');

        $decision = $this->decider()->decide(
            ['user_id' => self::USER_ID],
            'work_record.read',
            $this->facts('confidential', self::ORGANIZATION_UNIT_A),
        );

        $this->assertTrue($decision->isAllowed());
        $this->assertSame([
            'role_capability_allowed',
            'organization_unit_scope_matched',
            'classification_sufficient',
        ], $decision->reasonCodes);
        $this->assertSame('rbac-abac-v1', $decision->policyVersion);
    }

    public function test_active_delegation_capability_with_matching_scope_and_classification_allows_access(): void
    {
        $this->seedAllowingDelegation(scopeId: self::ORGANIZATION_UNIT_A, sensitivity: 'sensitive');

        $decision = $this->decider()->decide(
            ['user_id' => self::DELEGATE_USER_ID],
            'work_record.read',
            $this->facts('confidential', self::ORGANIZATION_UNIT_A),
        );

        $this->assertTrue($decision->isAllowed());
        $this->assertSame([
            'delegation_capability_allowed',
            'organization_unit_scope_matched',
            'classification_sufficient',
        ], $decision->reasonCodes);
    }

    public function test_active_explicit_deny_overrides_a_role_grant_before_classification(): void
    {
        $this->seedAllowingRole(scopeId: self::ORGANIZATION_UNIT_A, sensitivity: 'normal');
        $this->seedExplicitDeny(
            userId: self::USER_ID,
            organizationUnitId: self::ORGANIZATION_UNIT_A,
            classification: 'confidential',
        );

        $decision = $this->decider()->decide(
            ['user_id' => self::USER_ID],
            'work_record.read',
            $this->facts('confidential', self::ORGANIZATION_UNIT_A),
        );

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['explicit_deny'], $decision->reasonCodes);
    }

    public function test_active_explicit_deny_overrides_a_delegation_grant(): void
    {
        $this->seedAllowingDelegation(scopeId: self::ORGANIZATION_UNIT_A);
        $this->seedExplicitDeny(
            userId: self::DELEGATE_USER_ID,
            organizationUnitId: self::ORGANIZATION_UNIT_A,
        );

        $decision = $this->decider()->decide(
            ['user_id' => self::DELEGATE_USER_ID],
            'work_record.read',
            $this->facts('internal', self::ORGANIZATION_UNIT_A),
        );

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['explicit_deny'], $decision->reasonCodes);
    }

    public function test_active_explicit_deny_overrides_a_supervisory_relationship_grant(): void
    {
        $this->seedSupervisoryRelationship();
        $this->seedExplicitDeny(
            userId: self::NON_DELEGATE_USER_ID,
            organizationUnitId: self::ORGANIZATION_UNIT_B,
        );

        $decision = $this->decider()->decide(
            [
                'user_id' => self::NON_DELEGATE_USER_ID,
                'organization_unit_ids' => [self::ORGANIZATION_UNIT_A],
            ],
            'work_record.read',
            $this->facts('internal', self::ORGANIZATION_UNIT_B),
        );

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['explicit_deny'], $decision->reasonCodes);
    }

    public function test_expired_explicit_deny_does_not_override_an_active_role_grant(): void
    {
        $this->seedAllowingRole(scopeId: self::ORGANIZATION_UNIT_A);
        $this->seedExplicitDeny(
            userId: self::USER_ID,
            organizationUnitId: self::ORGANIZATION_UNIT_A,
            expiresAt: now()->subMinute(),
        );

        $decision = $this->decider()->decide(
            ['user_id' => self::USER_ID],
            'work_record.read',
            $this->facts('internal', self::ORGANIZATION_UNIT_A),
        );

        $this->assertTrue($decision->isAllowed());
    }

    public function test_explicit_deny_scoped_to_another_organization_unit_does_not_override_a_role_grant(): void
    {
        $this->seedAllowingRole(scopeId: self::ORGANIZATION_UNIT_A);
        $this->seedExplicitDeny(
            userId: self::USER_ID,
            organizationUnitId: self::ORGANIZATION_UNIT_B,
        );

        $decision = $this->decider()->decide(
            ['user_id' => self::USER_ID],
            'work_record.read',
            $this->facts('internal', self::ORGANIZATION_UNIT_A),
        );

        $this->assertTrue($decision->isAllowed());
    }

    public function test_missing_user_id_fails_closed(): void
    {
        $decision = $this->decider()->decide([], 'work_record.read', $this->facts());

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['actor_user_id_missing'], $decision->reasonCodes);
        $this->assertSame('rbac-abac-v1', $decision->policyVersion);
    }

    public function test_missing_facts_fails_closed(): void
    {
        $decision = $this->decider()->decide(['user_id' => self::USER_ID], 'work_record.read', null);

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['record_facts_unavailable'], $decision->reasonCodes);
        $this->assertSame('rbac-abac-v1', $decision->policyVersion);
    }

    public function test_capability_outside_the_catalog_fails_closed(): void
    {
        $decision = $this->decider()->decide(
            ['user_id' => self::USER_ID],
            'work_record.destroy',
            $this->facts(),
        );

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['capability_not_supported'], $decision->reasonCodes);
    }

    public function test_absent_active_role_assignment_fails_closed(): void
    {
        $decision = $this->decider()->decide(['user_id' => self::USER_ID], 'work_record.read', $this->facts());

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['active_role_assignment_not_found'], $decision->reasonCodes);
    }

    public function test_expired_role_assignment_fails_closed(): void
    {
        $this->seedAllowingRole(endAt: now()->subMinute());

        $decision = $this->decider()->decide(['user_id' => self::USER_ID], 'work_record.read', $this->facts());

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['role_assignment_expired'], $decision->reasonCodes);
    }

    public function test_expired_delegation_fails_closed(): void
    {
        $this->seedAllowingDelegation(endAt: now()->subMinute());

        $decision = $this->decider()->decide(
            ['user_id' => self::DELEGATE_USER_ID],
            'work_record.read',
            $this->facts(),
        );

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['delegation_expired'], $decision->reasonCodes);
    }

    public function test_non_null_assignment_scope_must_match_record_facts_organization_unit(): void
    {
        $this->seedAllowingRole(scopeId: self::ORGANIZATION_UNIT_A);

        $decision = $this->decider()->decide(
            ['user_id' => self::USER_ID],
            'work_record.read',
            $this->facts('internal', self::ORGANIZATION_UNIT_B),
        );

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['organization_unit_scope_mismatch'], $decision->reasonCodes);
    }

    public function test_non_null_delegation_scope_must_match_record_facts_organization_unit(): void
    {
        $this->seedAllowingDelegation(scopeId: self::ORGANIZATION_UNIT_A);

        $decision = $this->decider()->decide(
            ['user_id' => self::DELEGATE_USER_ID],
            'work_record.read',
            $this->facts('internal', self::ORGANIZATION_UNIT_B),
        );

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['organization_unit_scope_mismatch'], $decision->reasonCodes);
    }

    public function test_delegation_does_not_authorize_a_non_delegate(): void
    {
        $this->seedAllowingDelegation();

        $decision = $this->decider()->decide(
            ['user_id' => self::NON_DELEGATE_USER_ID],
            'work_record.read',
            $this->facts(),
        );

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['active_role_assignment_not_found'], $decision->reasonCodes);
    }

    public function test_capability_without_sufficient_classification_fails_closed(): void
    {
        $this->seedAllowingRole(sensitivity: 'normal');

        $decision = $this->decider()->decide(
            ['user_id' => self::USER_ID],
            'work_record.read',
            $this->facts('confidential'),
        );

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['classification_insufficient'], $decision->reasonCodes);
    }

    public function test_active_supervisory_relationship_with_matching_capability_and_scopes_allows_access(): void
    {
        $this->seedSupervisoryRelationship();

        $decision = $this->decider()->decide(
            [
                'user_id' => self::NON_DELEGATE_USER_ID,
                'organization_unit_ids' => [self::ORGANIZATION_UNIT_A],
            ],
            'work_record.read',
            $this->facts('internal', self::ORGANIZATION_UNIT_B),
        );

        $this->assertTrue($decision->isAllowed());
        $this->assertSame([
            'supervisory_relationship_capability_allowed',
            'supervisory_relationship_source_scope_matched',
            'supervisory_relationship_target_scope_matched',
        ], $decision->reasonCodes);
    }

    public function test_expired_supervisory_relationship_fails_closed(): void
    {
        $this->seedSupervisoryRelationship(validUntil: now()->subMinute());

        $decision = $this->decider()->decide(
            [
                'user_id' => self::NON_DELEGATE_USER_ID,
                'organization_unit_ids' => [self::ORGANIZATION_UNIT_A],
            ],
            'work_record.read',
            $this->facts('internal', self::ORGANIZATION_UNIT_B),
        );

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['supervisory_relationship_expired'], $decision->reasonCodes);
    }

    public function test_supervisory_relationship_target_scope_mismatch_fails_closed(): void
    {
        $this->seedSupervisoryRelationship();

        $decision = $this->decider()->decide(
            [
                'user_id' => self::NON_DELEGATE_USER_ID,
                'organization_unit_ids' => [self::ORGANIZATION_UNIT_A],
            ],
            'work_record.read',
            $this->facts('internal', self::ORGANIZATION_UNIT_A),
        );

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['supervisory_relationship_scope_mismatch'], $decision->reasonCodes);
    }

    public function test_supervisory_relationship_capability_mismatch_fails_closed(): void
    {
        $this->seedSupervisoryRelationship(capability: 'work_record.list');

        $decision = $this->decider()->decide(
            [
                'user_id' => self::NON_DELEGATE_USER_ID,
                'organization_unit_ids' => [self::ORGANIZATION_UNIT_A],
            ],
            'work_record.read',
            $this->facts('internal', self::ORGANIZATION_UNIT_B),
        );

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['active_role_assignment_not_found'], $decision->reasonCodes);
    }

    public function test_read_only_supervisory_relationship_cannot_grant_a_mutating_capability(): void
    {
        $this->seedSupervisoryRelationship(
            capability: 'work_record.submit',
            relationshipType: 'read_only',
        );

        $decision = $this->decider()->decide(
            [
                'user_id' => self::NON_DELEGATE_USER_ID,
                'organization_unit_ids' => [self::ORGANIZATION_UNIT_A],
            ],
            'work_record.submit',
            $this->facts('internal', self::ORGANIZATION_UNIT_B),
        );

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['supervisory_relationship_read_only_restricted'], $decision->reasonCodes);
    }

    private function decider(): RbacAbacDecideAccess
    {
        return new RbacAbacDecideAccess;
    }

    private function facts(
        string $classification = 'internal',
        ?string $organizationUnitId = null,
    ): RecordFacts {
        return new RecordFacts(
            ownerFacilityId: null,
            resourceType: 'work_record',
            classification: $classification,
            factsVersion: 'rbac-abac-test-facts-v1',
            organizationUnitId: $organizationUnitId,
        );
    }

    private function seedAllowingRole(
        ?string $scopeId = null,
        string $sensitivity = 'normal',
        ?Carbon $endAt = null,
    ): void {
        DB::table('roles')->insert([
            'id' => self::ROLE_ID,
            'code' => 'rbac_abac_reader',
            'name_ar' => 'قارئ صلاحيات',
            'name_en' => 'RBAC reader',
            'role_type' => 'administrative',
            'status' => 'active',
            'is_system_role' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('capabilities')->insert([
            'id' => self::CAPABILITY_ID,
            'module_code' => 'work_record',
            'capability_code' => 'work_record.read',
            'action' => 'read',
            'sensitivity' => $sensitivity,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('role_capabilities')->insert([
            'role_id' => self::ROLE_ID,
            'capability_id' => self::CAPABILITY_ID,
            'effect' => 'allow',
            'created_at' => now(),
        ]);
        DB::table('role_assignments')->insert([
            'id' => '018f6f7d-0c00-7000-8000-000000000907',
            'user_id' => self::USER_ID,
            'role_id' => self::ROLE_ID,
            'scope_id' => $scopeId,
            'start_at' => $endAt?->copy()->subMinute() ?? now()->subMinute(),
            'end_at' => $endAt,
            'status' => 'active',
            'granted_by_user_id' => self::GRANTED_BY_USER_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedAllowingDelegation(
        ?string $scopeId = null,
        string $sensitivity = 'normal',
        ?Carbon $endAt = null,
    ): void {
        DB::table('capabilities')->insert([
            'id' => self::CAPABILITY_ID,
            'module_code' => 'work_record',
            'capability_code' => 'work_record.read',
            'action' => 'read',
            'sensitivity' => $sensitivity,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('delegations')->insert([
            'id' => self::DELEGATION_ID,
            'delegator_user_id' => self::USER_ID,
            'delegate_user_id' => self::DELEGATE_USER_ID,
            'module_code' => 'work_record',
            'scope_id' => $scopeId,
            'start_at' => $endAt?->copy()->subMinute() ?? now()->subMinute(),
            'end_at' => $endAt ?? now()->addMinute(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('delegation_capabilities')->insert([
            'delegation_id' => self::DELEGATION_ID,
            'capability_code' => 'work_record.read',
        ]);
    }

    private function seedExplicitDeny(
        string $userId,
        ?string $organizationUnitId,
        ?Carbon $expiresAt = null,
        ?string $classification = null,
    ): void {
        DB::table('explicit_denies')->insert([
            'id' => self::EXPLICIT_DENY_ID,
            'user_id' => $userId,
            'capability_code' => 'work_record.read',
            'classification' => $classification,
            'organization_unit_id' => $organizationUnitId,
            'resource_pattern' => 'work_*',
            'reason' => 'Restricted test access.',
            'issued_by_user_id' => self::GRANTED_BY_USER_ID,
            'issued_at' => $expiresAt?->copy()->subMinute() ?? now()->subMinute(),
            'expires_at' => $expiresAt,
            'revocable' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedSupervisoryRelationship(
        string $capability = 'work_record.read',
        ?Carbon $validUntil = null,
        string $relationshipType = 'direct',
    ): void {
        $this->seedSupervisoryOrganizationReferences();

        DB::table('supervisory_relationships')->insert([
            'id' => self::SUPERVISORY_RELATIONSHIP_ID,
            'source_organization_unit_id' => self::ORGANIZATION_UNIT_A,
            'target_organization_unit_id' => self::ORGANIZATION_UNIT_B,
            'relationship_type' => $relationshipType,
            'valid_from' => $validUntil?->copy()->subMinute() ?? now()->subMinute(),
            'valid_until' => $validUntil ?? now()->addMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('relationship_capabilities')->insert([
            'id' => self::RELATIONSHIP_CAPABILITY_ID,
            'supervisory_relationship_id' => self::SUPERVISORY_RELATIONSHIP_ID,
            'module_code' => explode('.', $capability, 2)[0],
            'capability_code' => $capability,
        ]);
    }

    private function seedSupervisoryOrganizationReferences(): void
    {
        DB::table('clusters')->insert([
            'id' => self::RELATIONSHIP_CLUSTER_ID,
            'code' => 'RBAC-REL',
            'name_ar' => 'تجمع علاقة الصلاحيات',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('unit_types')->insert([
            'id' => self::RELATIONSHIP_UNIT_TYPE_ID,
            'code' => 'rbac_relationship_test_unit',
            'name_ar' => 'وحدة اختبار علاقة الصلاحيات',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([self::ORGANIZATION_UNIT_A => 'RBAC-REL-SOURCE', self::ORGANIZATION_UNIT_B => 'RBAC-REL-TARGET'] as $id => $code) {
            DB::table('organization_units')->insert([
                'id' => $id,
                'cluster_id' => self::RELATIONSHIP_CLUSTER_ID,
                'parent_id' => self::RELATIONSHIP_CLUSTER_ID,
                'parent_type' => 'cluster',
                'unit_type_id' => self::RELATIONSHIP_UNIT_TYPE_ID,
                'code' => $code,
                'name_ar' => 'وحدة اختبار علاقة الصلاحيات',
                'status' => 'active',
                'path_cache' => '/'.$id,
                'depth' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
