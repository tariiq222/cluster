<?php

namespace Modules\Authorization\Tests;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\PersistAccessDecision;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Authorization\Infrastructure\RbacAbacDecideAccess;
use Modules\Organization\Contracts\GetActiveSupervisoryRelationships;
use PHPUnit\Framework\Attributes\DataProvider;
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

    private const EXPLICIT_DENY_ID = '018f6f7d-0c00-7000-8000-000000000915';

    private const DENY_ROLE_ID = '018f6f7d-0c00-7000-8000-000000000916';

    private const CRITICAL_CAPABILITY_ID = '018f6f7d-0c00-7000-8000-000000000917';

    private const SUBMIT_CAPABILITY_ID = '018f6f7d-0c00-7000-8000-000000000918';

    private const CLUSTER_ID = '018f6f7d-0c00-7000-8000-000000000919';

    private const FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000920';

    private const RECORD_ID = '018f6f7d-0c00-7000-8000-000000000921';

    private const OTHER_RECORD_ID = '018f6f7d-0c00-7000-8000-000000000922';

    private const OTHER_CLUSTER_ID = '018f6f7d-0c00-7000-8000-000000000925';

    private const OTHER_FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000926';

    private const SECOND_ASSIGNMENT_ID = '018f6f7d-0c00-7000-8000-000000000923';

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000924';

    private FakeGetActiveSupervisoryRelationships $supervisoryRelationships;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'sensitive_access_events',
            'access_decisions',
            'explicit_denies',
            'classification_policies',
            'field_access_templates',
            'role_assignments',
            'role_capabilities',
            'delegation_capabilities',
            'delegations',
            'roles',
            'capabilities',
        ] as $table) {
            DB::table($table)->delete();
        }

        Carbon::setTestNow(Carbon::parse('2026-07-19 12:00:00', 'UTC'));
        $this->supervisoryRelationships = new FakeGetActiveSupervisoryRelationships;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function migrateDatabases(): void
    {
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        foreach ([
            'CreateAuthorizationRbacDataTables.php',
            'CreateAuthorizationExplicitDenyTables.php',
            'CreateAuthorizationFieldAuditTables.php',
            'ZAddAuthorizationHttpTables.php',
            'W13AddAuthorizationScopeTypes.php',
        ] as $migration) {
            $this->artisan('migrate', [
                '--path' => 'Modules/Authorization/Infrastructure/Persistence/Migrations/'.$migration,
                '--force' => true,
            ]);
        }
    }

    public function test_active_role_capability_with_matching_scope_and_classification_allows_access(): void
    {
        $this->seedAllowingRole(scopeId: self::ORGANIZATION_UNIT_A, sensitivity: 'sensitive');

        $decision = $this->decider()->decide(
            ['user_id' => self::USER_ID],
            'tasks.read',
            $this->facts('confidential', self::ORGANIZATION_UNIT_A),
        );

        $this->assertTrue($decision->isAllowed());
        $this->assertSame([
            'role_capability_allowed',
            'organization_unit_scope_matched',
            'classification_sufficient',
        ], $decision->reasonCodes);
        $this->assertSame('rbac-abac-v2', $decision->policyVersion);
        $this->assertNotNull($decision->decisionId);
        $this->assertSame(['read'], $decision->allowedActions);
    }

    public function test_active_delegation_capability_with_matching_scope_and_classification_allows_access(): void
    {
        $this->seedAllowingDelegation(scopeId: self::ORGANIZATION_UNIT_A, sensitivity: 'sensitive');

        $decision = $this->decider()->decide(
            ['user_id' => self::DELEGATE_USER_ID],
            'tasks.read',
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
            'tasks.read',
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
            'tasks.read',
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
            'tasks.read',
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
            'tasks.read',
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
            'tasks.read',
            $this->facts('internal', self::ORGANIZATION_UNIT_A),
        );

        $this->assertTrue($decision->isAllowed());
    }

    public function test_missing_user_id_fails_closed(): void
    {
        $decision = $this->decider()->decide([], 'tasks.read', $this->facts());

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['actor_user_id_missing'], $decision->reasonCodes);
        $this->assertSame('rbac-abac-v2', $decision->policyVersion);
    }

    public function test_missing_facts_fails_closed(): void
    {
        $decision = $this->decider()->decide(['user_id' => self::USER_ID], 'tasks.read', null);

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['record_facts_unavailable'], $decision->reasonCodes);
        $this->assertSame('rbac-abac-v2', $decision->policyVersion);
    }

    public function test_capability_outside_the_catalog_fails_closed(): void
    {
        $decision = $this->decider()->decide(
            ['user_id' => self::USER_ID],
            'tasks.destroy',
            $this->facts(),
        );

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['capability_not_supported'], $decision->reasonCodes);
    }

    public function test_absent_active_role_assignment_fails_closed(): void
    {
        $decision = $this->decider()->decide(['user_id' => self::USER_ID], 'tasks.read', $this->facts());

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['active_role_assignment_not_found'], $decision->reasonCodes);
    }

    public function test_expired_role_assignment_fails_closed(): void
    {
        $this->seedAllowingRole(endAt: now()->subMinute());

        $decision = $this->decider()->decide(['user_id' => self::USER_ID], 'tasks.read', $this->facts());

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['role_assignment_expired'], $decision->reasonCodes);
    }

    public function test_expired_delegation_fails_closed(): void
    {
        $this->seedAllowingDelegation(endAt: now()->subMinute());

        $decision = $this->decider()->decide(
            ['user_id' => self::DELEGATE_USER_ID],
            'tasks.read',
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
            'tasks.read',
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
            'tasks.read',
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
            'tasks.read',
            $this->facts(),
        );

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['active_role_assignment_not_found'], $decision->reasonCodes);
    }

    public function test_out_of_scope_role_grant_does_not_cancel_a_matching_delegation_grant(): void
    {
        // The user holds a role grant for tasks.read on unit A while
        // the record sits in unit B, AND a delegation grant for the same
        // capability that covers unit B. The delegation must win: the
        // non-matching role grant is a no-op, not a cancellation.
        $this->seedAllowingRole(scopeId: self::ORGANIZATION_UNIT_A);
        // seedAllowingDelegation would re-insert CAPABILITY_ID; insert the
        // delegation rows directly so the capability row stays unique.
        DB::table('delegations')->insert([
            'id' => self::DELEGATION_ID,
            'delegator_user_id' => self::USER_ID,
            'delegate_user_id' => self::DELEGATE_USER_ID,
            'module_code' => 'tasks',
            'scope_id' => self::ORGANIZATION_UNIT_B,
            'scope_type' => 'unit',
            'start_at' => now()->subMinute(),
            'end_at' => now()->addMinute(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('delegation_capabilities')->insert([
            'delegation_id' => self::DELEGATION_ID,
            'capability_code' => 'tasks.read',
        ]);

        $decision = $this->decider()->decide(
            ['user_id' => self::DELEGATE_USER_ID],
            'tasks.read',
            $this->facts('internal', self::ORGANIZATION_UNIT_B),
        );

        $this->assertTrue($decision->isAllowed());
        $this->assertSame('delegation_capability_allowed', $decision->reasonCodes[0]);
    }

    public function test_out_of_scope_role_grant_does_not_cancel_a_supervisory_relationship_grant(): void
    {
        // Same shadowing rule for the supervisory source: a role grant that
        // does not cover the record's unit must fall through to the active
        // supervisory relationship instead of denying.
        $this->seedAllowingRole(scopeId: self::ORGANIZATION_UNIT_A);
        $this->seedSupervisoryRelationship();

        $decision = $this->decider()->decide(
            ['user_id' => self::USER_ID, 'organization_unit_ids' => [self::ORGANIZATION_UNIT_A]],
            'tasks.read',
            $this->facts('internal', self::ORGANIZATION_UNIT_B),
        );

        $this->assertTrue($decision->isAllowed());
        $this->assertSame('supervisory_relationship_capability_allowed', $decision->reasonCodes[0]);
    }

    public function test_supervisory_relationship_path_honors_classification_export_policy(): void
    {
        // The classification policy governs every grant source: a
        // relationship grant must not bypass an export deny.
        $this->seedClassificationPolicy('confidential', 'tasks.read', exportPolicy: 'deny');
        $this->seedSupervisoryRelationship(capability: 'reporting.export');
        $this->seedCapabilityRow(self::SUBMIT_CAPABILITY_ID, 'reporting.export');

        $decision = $this->decider()->decide(
            ['user_id' => self::USER_ID, 'organization_unit_ids' => [self::ORGANIZATION_UNIT_A]],
            'reporting.export',
            $this->facts('confidential', self::ORGANIZATION_UNIT_B),
        );

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['classification_export_denied'], $decision->reasonCodes);
    }

    public function test_supervisory_relationship_path_enforces_classification_clearance_floor(): void
    {
        // A relationship grant must not bypass the classification clearance
        // floor: the supervisor's clearance comes from the decided
        // capability's sensitivity, so a low-sensitivity capability cannot
        // read a record whose policy floor requires a higher clearance.
        $this->seedCapabilityRow(self::CAPABILITY_ID, 'tasks.read', 'normal');
        $this->seedCapabilityRow(self::CRITICAL_CAPABILITY_ID, 'tasks.cancel', 'critical');
        $this->seedClassificationPolicy('confidential', 'tasks.cancel');
        $this->seedSupervisoryRelationship();

        $decision = $this->decider()->decide(
            ['user_id' => self::USER_ID, 'organization_unit_ids' => [self::ORGANIZATION_UNIT_A]],
            'tasks.read',
            $this->facts('confidential', self::ORGANIZATION_UNIT_B),
        );

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['classification_insufficient'], $decision->reasonCodes);
    }

    public function test_supervisory_relationship_with_sufficient_capability_sensitivity_allows_an_internal_record(): void
    {
        // The same relationship grants access when the record's required
        // clearance (internal, no policy floor) is met by the capability's
        // sensitivity-derived clearance.
        $this->seedCapabilityRow(self::CAPABILITY_ID, 'tasks.read', 'normal');
        $this->seedSupervisoryRelationship();

        $decision = $this->decider()->decide(
            ['user_id' => self::USER_ID, 'organization_unit_ids' => [self::ORGANIZATION_UNIT_A]],
            'tasks.read',
            $this->facts('internal', self::ORGANIZATION_UNIT_B),
        );

        $this->assertTrue($decision->isAllowed());
        $this->assertSame([
            'supervisory_relationship_capability_allowed',
            'supervisory_relationship_source_scope_matched',
            'supervisory_relationship_target_scope_matched',
        ], $decision->reasonCodes);
    }

    public function test_capability_without_sufficient_classification_fails_closed(): void
    {
        $this->seedAllowingRole(scopeId: self::ORGANIZATION_UNIT_A, sensitivity: 'normal');

        $decision = $this->decider()->decide(
            ['user_id' => self::USER_ID],
            'tasks.read',
            $this->facts('confidential', self::ORGANIZATION_UNIT_A),
        );

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['classification_insufficient'], $decision->reasonCodes);
    }

    public function test_active_supervisory_relationship_with_matching_capability_and_scopes_allows_access(): void
    {
        $this->seedCapabilityRow(self::CAPABILITY_ID, 'tasks.read');
        $this->seedSupervisoryRelationship();

        $decision = $this->decider()->decide(
            [
                'user_id' => self::NON_DELEGATE_USER_ID,
                'organization_unit_ids' => [self::ORGANIZATION_UNIT_A],
            ],
            'tasks.read',
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
            'tasks.read',
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
            'tasks.read',
            $this->facts('internal', self::ORGANIZATION_UNIT_A),
        );

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['supervisory_relationship_scope_mismatch'], $decision->reasonCodes);
    }

    public function test_supervisory_relationship_capability_mismatch_fails_closed(): void
    {
        $this->seedSupervisoryRelationship(capability: 'tasks.list');

        $decision = $this->decider()->decide(
            [
                'user_id' => self::NON_DELEGATE_USER_ID,
                'organization_unit_ids' => [self::ORGANIZATION_UNIT_A],
            ],
            'tasks.read',
            $this->facts('internal', self::ORGANIZATION_UNIT_B),
        );

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['active_role_assignment_not_found'], $decision->reasonCodes);
    }

    public function test_read_only_supervisory_relationship_cannot_grant_a_mutating_capability(): void
    {
        $this->seedSupervisoryRelationship(
            capability: 'tasks.create',
            relationshipType: 'read_only',
        );

        $decision = $this->decider()->decide(
            [
                'user_id' => self::NON_DELEGATE_USER_ID,
                'organization_unit_ids' => [self::ORGANIZATION_UNIT_A],
            ],
            'tasks.create',
            $this->facts('internal', self::ORGANIZATION_UNIT_B),
        );

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['supervisory_relationship_read_only_restricted'], $decision->reasonCodes);
    }

    public function test_role_deny_grant_overrides_a_matching_allow_grant(): void
    {
        $this->seedAllowingRole(scopeId: self::ORGANIZATION_UNIT_A, scopeType: 'unit', sensitivity: 'sensitive');
        $this->seedDenyingRole(scopeId: self::ORGANIZATION_UNIT_A, scopeType: 'unit');

        $decision = $this->decider()->decide(
            ['user_id' => self::USER_ID],
            'tasks.read',
            $this->facts('internal', self::ORGANIZATION_UNIT_A),
        );

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['role_capability_denied'], $decision->reasonCodes);
    }

    public function test_role_deny_grant_in_a_non_matching_scope_does_not_block_an_allow_grant(): void
    {
        $this->seedAllowingRole(scopeId: self::ORGANIZATION_UNIT_A, scopeType: 'unit', sensitivity: 'sensitive');
        $this->seedDenyingRole(scopeId: self::ORGANIZATION_UNIT_B, scopeType: 'unit');

        $decision = $this->decider()->decide(
            ['user_id' => self::USER_ID],
            'tasks.read',
            $this->facts('internal', self::ORGANIZATION_UNIT_A),
        );

        $this->assertTrue($decision->isAllowed());
        $this->assertSame([
            'role_capability_allowed',
            'organization_unit_scope_matched',
            'classification_sufficient',
        ], $decision->reasonCodes);
    }

    public function test_cluster_scope_assignment_covers_records_in_its_facilities_and_units(): void
    {
        $this->seedAllowingRole(scopeId: self::CLUSTER_ID, scopeType: 'cluster', sensitivity: 'sensitive');

        $decision = $this->decider()->decide(
            ['user_id' => self::USER_ID],
            'tasks.read',
            $this->facts(
                'confidential',
                self::ORGANIZATION_UNIT_A,
                clusterId: self::CLUSTER_ID,
                ownerFacilityId: self::FACILITY_ID,
            ),
        );

        $this->assertTrue($decision->isAllowed());

        $otherCluster = $this->decider()->decide(
            ['user_id' => self::USER_ID],
            'tasks.read',
            $this->facts(
                'confidential',
                self::ORGANIZATION_UNIT_A,
                clusterId: self::FACILITY_ID,
                ownerFacilityId: self::FACILITY_ID,
            ),
        );

        $this->assertSame('deny', $otherCluster->decision);
        $this->assertSame(['organization_unit_scope_mismatch'], $otherCluster->reasonCodes);
    }

    public function test_facility_scope_assignment_covers_records_owned_by_the_facility(): void
    {
        $this->seedAllowingRole(scopeId: self::FACILITY_ID, scopeType: 'facility', sensitivity: 'sensitive');

        $decision = $this->decider()->decide(
            ['user_id' => self::USER_ID],
            'tasks.read',
            $this->facts(
                'confidential',
                self::ORGANIZATION_UNIT_B,
                clusterId: self::CLUSTER_ID,
                ownerFacilityId: self::FACILITY_ID,
            ),
        );

        $this->assertTrue($decision->isAllowed());
    }

    public function test_unit_scope_assignment_does_not_cover_a_sibling_unit(): void
    {
        $this->seedAllowingRole(scopeId: self::ORGANIZATION_UNIT_A, scopeType: 'unit');

        $decision = $this->decider()->decide(
            ['user_id' => self::USER_ID],
            'tasks.read',
            $this->facts(
                'internal',
                self::ORGANIZATION_UNIT_B,
                clusterId: self::CLUSTER_ID,
                ownerFacilityId: self::FACILITY_ID,
            ),
        );

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['organization_unit_scope_mismatch'], $decision->reasonCodes);
    }

    public function test_record_set_scope_assignment_matches_only_that_record(): void
    {
        $this->seedAllowingRole(scopeId: self::RECORD_ID, scopeType: 'record_set', sensitivity: 'sensitive');

        $matching = $this->decider()->decide(
            ['user_id' => self::USER_ID],
            'tasks.read',
            $this->facts('confidential', self::ORGANIZATION_UNIT_B, recordId: self::RECORD_ID),
        );
        $other = $this->decider()->decide(
            ['user_id' => self::USER_ID],
            'tasks.read',
            $this->facts('confidential', self::ORGANIZATION_UNIT_B, recordId: self::OTHER_RECORD_ID),
        );

        $this->assertTrue($matching->isAllowed());
        $this->assertSame('deny', $other->decision);
        $this->assertSame(['organization_unit_scope_mismatch'], $other->reasonCodes);
    }

    #[DataProvider('scopeMatrixCases')]
    public function test_scope_matrix_matches_only_the_corresponding_record_fact(
        string $scopeType,
        string $scopeId,
        array $facts,
        string $expectedDecision,
    ): void {
        $this->seedAllowingRole(scopeId: $scopeId, scopeType: $scopeType);

        $decision = $this->decider()->decide(
            ['user_id' => self::USER_ID],
            'tasks.read',
            new RecordFacts(
                ownerFacilityId: $facts['owner_facility_id'],
                resourceType: 'task',
                classification: 'internal',
                factsVersion: 'rbac-abac-test-scope-matrix-v1',
                organizationUnitId: $facts['organization_unit_id'],
                recordId: $facts['record_id'],
                clusterId: $facts['cluster_id'],
            ),
        );

        $this->assertSame($expectedDecision, $decision->decision);
    }

    /**
     * @return array<string, array{
     *     0: 'cluster'|'facility'|'unit'|'record_set',
     *     1: string,
     *     2: array{
     *         owner_facility_id: string,
     *         organization_unit_id: string,
     *         record_id: string,
     *         cluster_id: string,
     *     },
     *     3: 'allow'|'deny',
     * }>
     */
    public static function scopeMatrixCases(): array
    {
        return array_map(
            static fn (array $case): array => [
                $case['scope_type'],
                $case['scope_id'],
                $case['facts'],
                $case['expected_decision'],
            ],
            [
                'cluster matching id' => [
                    'scope_type' => 'cluster',
                    'scope_id' => self::CLUSTER_ID,
                    'facts' => [
                        'owner_facility_id' => self::FACILITY_ID,
                        'organization_unit_id' => self::ORGANIZATION_UNIT_A,
                        'record_id' => self::RECORD_ID,
                        'cluster_id' => self::CLUSTER_ID,
                    ],
                    'expected_decision' => 'allow',
                ],
                'cluster mismatching id' => [
                    'scope_type' => 'cluster',
                    'scope_id' => self::CLUSTER_ID,
                    'facts' => [
                        'owner_facility_id' => self::FACILITY_ID,
                        'organization_unit_id' => self::ORGANIZATION_UNIT_A,
                        'record_id' => self::RECORD_ID,
                        'cluster_id' => self::OTHER_CLUSTER_ID,
                    ],
                    'expected_decision' => 'deny',
                ],
                'facility matching id' => [
                    'scope_type' => 'facility',
                    'scope_id' => self::FACILITY_ID,
                    'facts' => [
                        'owner_facility_id' => self::FACILITY_ID,
                        'organization_unit_id' => self::ORGANIZATION_UNIT_A,
                        'record_id' => self::RECORD_ID,
                        'cluster_id' => self::CLUSTER_ID,
                    ],
                    'expected_decision' => 'allow',
                ],
                'facility mismatching id' => [
                    'scope_type' => 'facility',
                    'scope_id' => self::FACILITY_ID,
                    'facts' => [
                        'owner_facility_id' => self::OTHER_FACILITY_ID,
                        'organization_unit_id' => self::ORGANIZATION_UNIT_A,
                        'record_id' => self::RECORD_ID,
                        'cluster_id' => self::CLUSTER_ID,
                    ],
                    'expected_decision' => 'deny',
                ],
                'unit matching id' => [
                    'scope_type' => 'unit',
                    'scope_id' => self::ORGANIZATION_UNIT_A,
                    'facts' => [
                        'owner_facility_id' => self::FACILITY_ID,
                        'organization_unit_id' => self::ORGANIZATION_UNIT_A,
                        'record_id' => self::RECORD_ID,
                        'cluster_id' => self::CLUSTER_ID,
                    ],
                    'expected_decision' => 'allow',
                ],
                'unit mismatching id' => [
                    'scope_type' => 'unit',
                    'scope_id' => self::ORGANIZATION_UNIT_A,
                    'facts' => [
                        'owner_facility_id' => self::FACILITY_ID,
                        'organization_unit_id' => self::ORGANIZATION_UNIT_B,
                        'record_id' => self::RECORD_ID,
                        'cluster_id' => self::CLUSTER_ID,
                    ],
                    'expected_decision' => 'deny',
                ],
                'record set matching id' => [
                    'scope_type' => 'record_set',
                    'scope_id' => self::RECORD_ID,
                    'facts' => [
                        'owner_facility_id' => self::FACILITY_ID,
                        'organization_unit_id' => self::ORGANIZATION_UNIT_A,
                        'record_id' => self::RECORD_ID,
                        'cluster_id' => self::CLUSTER_ID,
                    ],
                    'expected_decision' => 'allow',
                ],
                'record set mismatching id' => [
                    'scope_type' => 'record_set',
                    'scope_id' => self::RECORD_ID,
                    'facts' => [
                        'owner_facility_id' => self::FACILITY_ID,
                        'organization_unit_id' => self::ORGANIZATION_UNIT_A,
                        'record_id' => self::OTHER_RECORD_ID,
                        'cluster_id' => self::CLUSTER_ID,
                    ],
                    'expected_decision' => 'deny',
                ],
            ],
        );
    }

    public function test_legacy_null_scope_assignment_no_longer_grants_global_access(): void
    {
        $this->seedAllowingRole(scopeId: null, scopeType: null);

        $decision = $this->decider()->decide(
            ['user_id' => self::USER_ID],
            'tasks.read',
            $this->facts('internal', self::ORGANIZATION_UNIT_A),
        );

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['organization_unit_scope_mismatch'], $decision->reasonCodes);
    }

    public function test_classification_policy_raising_required_clearance_denies_a_lower_clearance_grant(): void
    {
        $this->seedAllowingRole(scopeId: self::ORGANIZATION_UNIT_A, scopeType: 'unit', sensitivity: 'sensitive');
        $this->seedCapabilityRow(self::CRITICAL_CAPABILITY_ID, 'tasks.cancel', 'critical');
        $this->seedClassificationPolicy('confidential', 'tasks.cancel');

        $denied = $this->decider()->decide(
            ['user_id' => self::USER_ID],
            'tasks.read',
            $this->facts('confidential', self::ORGANIZATION_UNIT_A),
        );

        $this->assertSame('deny', $denied->decision);
        $this->assertSame(['classification_insufficient'], $denied->reasonCodes);

        DB::table('capabilities')->where('id', self::CAPABILITY_ID)->update(['sensitivity' => 'critical']);

        $allowed = $this->decider()->decide(
            ['user_id' => self::USER_ID],
            'tasks.read',
            $this->facts('confidential', self::ORGANIZATION_UNIT_A),
        );

        $this->assertTrue($allowed->isAllowed());
    }

    public function test_classification_download_policy_deny_blocks_a_download_capability_and_audit_adds_an_obligation(): void
    {
        $this->seedCapabilityRow(self::CAPABILITY_ID, 'documents.download', 'sensitive');
        $this->seedRoleRow(self::ROLE_ID);
        $this->seedRoleCapabilityRow(self::ROLE_ID, self::CAPABILITY_ID, 'allow');
        $this->seedAssignmentRow(self::USER_ID, self::ROLE_ID, self::ORGANIZATION_UNIT_A, 'unit');
        $this->seedClassificationPolicy('confidential', 'documents.download', downloadPolicy: 'deny');

        $denied = $this->decider()->decide(
            ['user_id' => self::USER_ID],
            'documents.download',
            $this->facts('confidential', self::ORGANIZATION_UNIT_A, resourceType: 'document'),
        );

        $this->assertSame('deny', $denied->decision);
        $this->assertSame(['classification_download_denied'], $denied->reasonCodes);

        DB::table('classification_policies')->where('classification_code', 'confidential')->update(['download_policy' => 'audit']);

        $allowed = $this->decider()->decide(
            ['user_id' => self::USER_ID],
            'documents.download',
            $this->facts('confidential', self::ORGANIZATION_UNIT_A, resourceType: 'document'),
        );

        $this->assertTrue($allowed->isAllowed());
        $this->assertSame(['audit'], $allowed->obligations);
    }

    public function test_field_access_template_projects_hidden_masked_readonly_and_editable_fields(): void
    {
        $this->seedAllowingRole(scopeId: self::ORGANIZATION_UNIT_A, scopeType: 'unit', sensitivity: 'sensitive');
        $this->seedFieldAccessTemplate('tasks.default', [
            'payload.summary' => 'read',
            'payload.budget_amount' => 'mask',
            'payload.reviewer_note' => 'edit',
            'payload.internal_memo' => 'hide',
        ]);

        $decision = $this->decider()->decide(
            ['user_id' => self::USER_ID],
            'tasks.read',
            $this->facts('confidential', self::ORGANIZATION_UNIT_A, fieldPolicyKey: 'tasks.default'),
        );

        $this->assertTrue($decision->isAllowed());
        $this->assertSame([
            'payload.summary' => 'readonly',
            'payload.budget_amount' => 'masked',
            'payload.reviewer_note' => 'readonly',
            'payload.internal_memo' => 'hidden',
        ], $decision->fieldAccess);
    }

    public function test_missing_field_access_template_fails_closed_with_a_wildcard_hidden_rule(): void
    {
        $this->seedAllowingRole(scopeId: self::ORGANIZATION_UNIT_A, scopeType: 'unit', sensitivity: 'sensitive');

        $decision = $this->decider()->decide(
            ['user_id' => self::USER_ID],
            'tasks.read',
            $this->facts('confidential', self::ORGANIZATION_UNIT_A, fieldPolicyKey: 'tasks.missing'),
        );

        $this->assertTrue($decision->isAllowed());
        $this->assertSame(['*' => 'hidden'], $decision->fieldAccess);
    }

    public function test_allow_on_a_confidential_record_persists_the_decision_and_a_sensitive_access_event(): void
    {
        $this->seedAllowingRole(scopeId: self::ORGANIZATION_UNIT_A, scopeType: 'unit', sensitivity: 'sensitive');

        $decision = $this->decider()->decide(
            ['user_id' => self::USER_ID, 'correlation_id' => self::CORRELATION_ID],
            'tasks.read',
            $this->facts('confidential', self::ORGANIZATION_UNIT_A, recordId: self::RECORD_ID),
        );

        $this->assertTrue($decision->isAllowed());
        $this->assertNotNull($decision->decisionId);
        $this->assertDatabaseHas('access_decisions', [
            'id' => $decision->decisionId,
            'decision' => 'allow',
            'action' => 'tasks.read',
            'resource_type' => 'task',
            'resource_id' => self::RECORD_ID,
            'policy_version' => 'rbac-abac-v2',
            'classification' => 'confidential',
            'correlation_id' => self::CORRELATION_ID,
            'actor_user_id' => self::USER_ID,
        ]);
        $this->assertDatabaseHas('sensitive_access_events', [
            'access_decision_id' => $decision->decisionId,
            'actor_user_id' => self::USER_ID,
            'original_actor_user_id' => self::USER_ID,
            'resource_type' => 'task',
            'resource_id' => self::RECORD_ID,
            'classification_code' => 'confidential',
            'correlation_id' => self::CORRELATION_ID,
            'idempotency_key_hash' => hash('sha256', (string) $decision->decisionId),
        ]);
    }

    public function test_deny_decisions_are_persisted_with_their_reason_codes(): void
    {
        $decision = $this->decider()->decide(
            ['user_id' => self::USER_ID],
            'tasks.read',
            $this->facts('internal', self::ORGANIZATION_UNIT_A),
        );

        $this->assertSame('deny', $decision->decision);
        $this->assertNotNull($decision->decisionId);
        $this->assertDatabaseHas('access_decisions', [
            'id' => $decision->decisionId,
            'decision' => 'deny',
            'action' => 'tasks.read',
            'reason_codes' => json_encode(['active_role_assignment_not_found']),
            'actor_user_id' => self::USER_ID,
        ]);
    }

    public function test_evaluate_only_does_not_persist_a_decision_or_sensitive_event(): void
    {
        $this->seedAllowingRole(scopeId: self::ORGANIZATION_UNIT_A, scopeType: 'unit', sensitivity: 'sensitive');

        $decision = $this->decider()->evaluateOnly(
            ['user_id' => self::USER_ID, 'correlation_id' => self::CORRELATION_ID],
            'tasks.read',
            $this->facts('confidential', self::ORGANIZATION_UNIT_A, recordId: self::RECORD_ID),
        );

        $this->assertTrue($decision->isAllowed());
        $this->assertNull($decision->decisionId);
        $this->assertDatabaseCount('access_decisions', 0);
        $this->assertDatabaseCount('sensitive_access_events', 0);
    }

    public function test_evaluate_only_denies_without_persisting_when_authorization_is_missing(): void
    {
        $decision = $this->decider()->evaluateOnly(
            ['user_id' => self::USER_ID],
            'tasks.read',
            $this->facts('internal', self::ORGANIZATION_UNIT_A),
        );

        $this->assertSame('deny', $decision->decision);
        $this->assertNull($decision->decisionId);
        $this->assertDatabaseCount('access_decisions', 0);
        $this->assertDatabaseCount('sensitive_access_events', 0);
    }

    public function test_persistence_failure_returns_a_fail_closed_decision_without_identity(): void
    {
        $this->seedAllowingRole(scopeId: self::ORGANIZATION_UNIT_A, scopeType: 'unit');
        $persistence = new class implements PersistAccessDecision
        {
            public function persist(AccessDecision $decision, ?RecordFacts $facts, array $actor): bool
            {
                return false;
            }
        };
        $decider = new RbacAbacDecideAccess($this->supervisoryRelationships, $persistence);

        $decision = $decider->decide(
            ['user_id' => self::USER_ID],
            'tasks.read',
            $this->facts('internal', self::ORGANIZATION_UNIT_A),
        );

        $this->assertSame('deny', $decision->decision);
        $this->assertSame(['decision_persistence_unavailable'], $decision->reasonCodes);
        $this->assertNull($decision->decisionId);
    }

    public function test_allowed_actions_projects_same_module_scope_matched_grants(): void
    {
        $this->seedAllowingRole(scopeId: self::ORGANIZATION_UNIT_A, scopeType: 'unit');
        $this->seedCapabilityRow(self::SUBMIT_CAPABILITY_ID, 'tasks.create');
        $this->seedRoleCapabilityRow(self::ROLE_ID, self::SUBMIT_CAPABILITY_ID, 'allow');

        $decision = $this->decider()->decide(
            ['user_id' => self::USER_ID],
            'tasks.read',
            $this->facts('internal', self::ORGANIZATION_UNIT_A),
        );

        $this->assertTrue($decision->isAllowed());
        $this->assertSame(['create', 'read'], $decision->allowedActions);
    }

    public function test_allowed_actions_excludes_a_capability_with_an_active_explicit_deny(): void
    {
        $this->seedAllowingRole(scopeId: self::ORGANIZATION_UNIT_A, scopeType: 'unit');
        $this->seedCapabilityRow(self::SUBMIT_CAPABILITY_ID, 'tasks.create');
        $this->seedRoleCapabilityRow(self::ROLE_ID, self::SUBMIT_CAPABILITY_ID, 'allow');
        $this->seedExplicitDeny(
            userId: self::USER_ID,
            organizationUnitId: self::ORGANIZATION_UNIT_A,
            capabilityCode: 'tasks.create',
        );

        $decision = $this->decider()->decide(
            ['user_id' => self::USER_ID],
            'tasks.read',
            $this->facts('internal', self::ORGANIZATION_UNIT_A),
        );

        $this->assertTrue($decision->isAllowed());
        $this->assertSame(['read'], $decision->allowedActions);
    }

    public function test_allowed_actions_excludes_a_capability_with_an_active_role_deny(): void
    {
        $this->seedAllowingRole(scopeId: self::ORGANIZATION_UNIT_A, scopeType: 'unit');
        $this->seedCapabilityRow(self::SUBMIT_CAPABILITY_ID, 'tasks.create');
        $this->seedRoleCapabilityRow(self::ROLE_ID, self::SUBMIT_CAPABILITY_ID, 'allow');

        $this->seedRoleRow(self::DENY_ROLE_ID, 'rbac_abac_submit_denier');
        $this->seedRoleCapabilityRow(self::DENY_ROLE_ID, self::SUBMIT_CAPABILITY_ID, 'deny');
        $this->seedAssignmentRow(self::USER_ID, self::DENY_ROLE_ID, self::ORGANIZATION_UNIT_A, 'unit');

        $decision = $this->decider()->decide(
            ['user_id' => self::USER_ID],
            'tasks.read',
            $this->facts('internal', self::ORGANIZATION_UNIT_A),
        );

        $this->assertTrue($decision->isAllowed());
        $this->assertSame(['read'], $decision->allowedActions);
    }

    private function decider(): RbacAbacDecideAccess
    {
        return new RbacAbacDecideAccess($this->supervisoryRelationships);
    }

    private function facts(
        string $classification = 'internal',
        ?string $organizationUnitId = null,
        ?string $recordId = null,
        ?string $clusterId = null,
        ?string $ownerFacilityId = null,
        ?string $fieldPolicyKey = null,
        string $resourceType = 'task',
    ): RecordFacts {
        return new RecordFacts(
            ownerFacilityId: $ownerFacilityId,
            resourceType: $resourceType,
            classification: $classification,
            factsVersion: 'rbac-abac-test-facts-v1',
            organizationUnitId: $organizationUnitId,
            recordId: $recordId,
            clusterId: $clusterId,
            fieldPolicyKey: $fieldPolicyKey,
        );
    }

    private function seedAllowingRole(
        ?string $scopeId = null,
        string $sensitivity = 'normal',
        ?Carbon $endAt = null,
        ?string $scopeType = null,
    ): void {
        $this->seedRoleRow(self::ROLE_ID);
        $this->seedCapabilityRow(self::CAPABILITY_ID, 'tasks.read', $sensitivity);
        $this->seedRoleCapabilityRow(self::ROLE_ID, self::CAPABILITY_ID, 'allow');
        DB::table('role_assignments')->insert([
            'id' => '018f6f7d-0c00-7000-8000-000000000907',
            'user_id' => self::USER_ID,
            'role_id' => self::ROLE_ID,
            'scope_id' => $scopeId,
            'scope_type' => $scopeType,
            'start_at' => $endAt?->copy()->subMinute() ?? now()->subMinute(),
            'end_at' => $endAt,
            'status' => 'active',
            'granted_by_user_id' => self::GRANTED_BY_USER_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedDenyingRole(?string $scopeId, ?string $scopeType): void
    {
        $this->seedRoleRow(self::DENY_ROLE_ID, 'rbac_abac_denier');
        $this->seedRoleCapabilityRow(self::DENY_ROLE_ID, self::CAPABILITY_ID, 'deny');
        $this->seedAssignmentRow(self::USER_ID, self::DENY_ROLE_ID, $scopeId, $scopeType);
    }

    private function seedRoleRow(string $roleId, string $code = 'rbac_abac_reader'): void
    {
        DB::table('roles')->insert([
            'id' => $roleId,
            'code' => $code,
            'name_ar' => 'قارئ صلاحيات',
            'name_en' => 'RBAC reader',
            'role_type' => 'administrative',
            'status' => 'active',
            'is_system_role' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedCapabilityRow(string $capabilityId, string $capabilityCode, string $sensitivity = 'normal'): void
    {
        DB::table('capabilities')->insert([
            'id' => $capabilityId,
            'module_code' => explode('.', $capabilityCode, 2)[0],
            'capability_code' => $capabilityCode,
            'action' => substr($capabilityCode, (int) strrpos($capabilityCode, '.') + 1),
            'sensitivity' => $sensitivity,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedRoleCapabilityRow(string $roleId, string $capabilityId, string $effect): void
    {
        DB::table('role_capabilities')->insert([
            'role_id' => $roleId,
            'capability_id' => $capabilityId,
            'effect' => $effect,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedAssignmentRow(string $userId, string $roleId, ?string $scopeId, ?string $scopeType): void
    {
        DB::table('role_assignments')->insert([
            'id' => self::SECOND_ASSIGNMENT_ID,
            'user_id' => $userId,
            'role_id' => $roleId,
            'scope_id' => $scopeId,
            'scope_type' => $scopeType,
            'start_at' => now()->subMinute(),
            'end_at' => null,
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
        ?string $scopeType = null,
    ): void {
        $this->seedCapabilityRow(self::CAPABILITY_ID, 'tasks.read', $sensitivity);
        DB::table('delegations')->insert([
            'id' => self::DELEGATION_ID,
            'delegator_user_id' => self::USER_ID,
            'delegate_user_id' => self::DELEGATE_USER_ID,
            'module_code' => 'tasks',
            'scope_id' => $scopeId,
            'scope_type' => $scopeType,
            'start_at' => $endAt?->copy()->subMinute() ?? now()->subMinute(),
            'end_at' => $endAt ?? now()->addMinute(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('delegation_capabilities')->insert([
            'delegation_id' => self::DELEGATION_ID,
            'capability_code' => 'tasks.read',
        ]);
    }

    private function seedExplicitDeny(
        string $userId,
        ?string $organizationUnitId,
        ?Carbon $expiresAt = null,
        ?string $classification = null,
        string $capabilityCode = 'tasks.read',
    ): void {
        DB::table('explicit_denies')->insert([
            'id' => self::EXPLICIT_DENY_ID,
            'user_id' => $userId,
            'capability_code' => $capabilityCode,
            'classification' => $classification,
            'organization_unit_id' => $organizationUnitId,
            'resource_pattern' => 'task*',
            'reason' => 'Restricted test access.',
            'issued_by_user_id' => self::GRANTED_BY_USER_ID,
            'issued_at' => $expiresAt?->copy()->subMinute() ?? now()->subMinute(),
            'expires_at' => $expiresAt,
            'revocable' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedClassificationPolicy(
        string $classification,
        string $minimumCapability,
        string $exportPolicy = 'allow',
        string $downloadPolicy = 'allow',
    ): void {
        DB::table('classification_policies')->insert([
            'classification_code' => $classification,
            'minimum_capability' => $minimumCapability,
            'export_policy' => $exportPolicy,
            'download_policy' => $downloadPolicy,
            'policy_version' => 'v1',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param  array<string, string>  $fields */
    private function seedFieldAccessTemplate(string $fieldPolicyKey, array $fields): void
    {
        DB::table('field_access_templates')->insert([
            'field_policy_key' => $fieldPolicyKey,
            'module_code' => 'tasks',
            'policy_definition' => json_encode(['fields' => $fields], JSON_THROW_ON_ERROR),
            'policy_version' => 'v1',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedSupervisoryRelationship(
        string $capability = 'tasks.read',
        ?Carbon $validUntil = null,
        string $relationshipType = 'direct',
    ): void {
        $this->supervisoryRelationships->seed(self::ORGANIZATION_UNIT_A, [
            'supervisory_relationship_id' => self::SUPERVISORY_RELATIONSHIP_ID,
            'source_organization_unit_id' => self::ORGANIZATION_UNIT_A,
            'target_organization_unit_id' => self::ORGANIZATION_UNIT_B,
            'relationship_type' => $relationshipType,
            'valid_from' => ($validUntil?->copy()->subMinute() ?? now()->subMinute())->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'valid_until' => ($validUntil ?? now()->addMinute())->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'relationship_capabilities' => [[
                'relationship_capability_id' => self::RELATIONSHIP_CAPABILITY_ID,
                'module_code' => explode('.', $capability, 2)[0],
                'capability_code' => $capability,
            ]],
        ]);
    }
}

final class FakeGetActiveSupervisoryRelationships implements GetActiveSupervisoryRelationships
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $relationshipsBySource = [];

    /** @param  array<string, mixed>  $relationship */
    public function seed(string $sourceOrganizationUnitId, array $relationship): void
    {
        $this->relationshipsBySource[$sourceOrganizationUnitId][] = $relationship;
    }

    public function forSourceOrganizationUnit(string $sourceOrganizationUnitId): array
    {
        return $this->relationshipsBySource[$sourceOrganizationUnitId] ?? [];
    }
}
