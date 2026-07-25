<?php

namespace Modules\WorkRecords\Features\GetAuthorizedWorkRecord\Handler\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Organization\Contracts\ResolveOrganizationScopeAncestry;
use Modules\WorkRecords\Features\GetAuthorizedWorkRecord\Handler\GetAuthorizedWorkRecordHandler;
use Tests\TestCase;

final class GetAuthorizedWorkRecordFieldMaskingTest extends TestCase
{
    use RefreshDatabase;

    private const FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000601';

    private const CLUSTER_ID = '018f6f7d-0c00-7000-8000-000000000602';

    private const PRINCIPAL_ID = '018f6f7d-0c00-7000-8000-000000000603';

    private const RECORD_ID = '018f6f7d-0c00-7000-8000-000000000604';

    private const FIELD_POLICY_KEY = 'work_record.classified';

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('clusters')->insert([
            'id' => self::CLUSTER_ID,
            'code' => 'WR-CLUSTER-MASK',
            'name_ar' => 'تجمع',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('facilities')->insert([
            'id' => self::FACILITY_ID,
            'cluster_id' => self::CLUSTER_ID,
            'code' => 'WR-FAC-MASK',
            'name_ar' => 'منشأة',
            'status' => 'active',
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('work_records')->insert([
            'id' => self::RECORD_ID,
            'record_number' => 'WR-MASK-1',
            'work_type_version_id' => '0197f0e0-0000-7000-8000-000000000001',
            'owner_facility_id' => self::FACILITY_ID,
            'creator_user_id' => self::PRINCIPAL_ID,
            'status' => 'submitted',
            'classification' => 'confidential',
            'field_policy_key' => self::FIELD_POLICY_KEY,
            'payload' => json_encode([
                'summary' => 'public summary',
                'budget_amount' => 50000,
                'reviewer_note' => 'private reviewer note',
                'internal_memo' => 'classified internal memo',
                'unmapped' => 'should be hidden by wildcard',
            ], JSON_THROW_ON_ERROR),
            'lock_version' => 1,
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_handler_masks_and_hides_payload_fields_using_payload_dot_path_lookups(): void
    {
        $decider = new FieldPolicyDecider;
        $ancestry = new SingleAncestry(self::CLUSTER_ID, self::FACILITY_ID);
        $handler = new GetAuthorizedWorkRecordHandler($decider, $ancestry);

        $result = $handler->handle(
            ['user_id' => self::PRINCIPAL_ID, 'facility_id' => self::FACILITY_ID],
            self::RECORD_ID,
        );

        $this->assertNotNull($result);
        $this->assertSame(self::RECORD_ID, $result['id']);
        $this->assertSame(['*' => 'hidden'], $result['field_access']);
        $this->assertArrayHasKey('payload', $result);
        $this->assertArrayNotHasKey('summary', $result['payload'], 'read field should be visible');
        $this->assertArrayNotHasKey('reviewer_note', $result['payload'], 'edit field should be visible as readonly');
        $this->assertArrayNotHasKey('budget_amount', $result['payload'], 'mask field should be visible as masked');
        $this->assertArrayNotHasKey('internal_memo', $result['payload'], 'hide field should be hidden');
        $this->assertArrayNotHasKey('unmapped', $result['payload'], 'unmapped field should follow wildcard (hidden)');
    }

    public function test_handler_preserves_raw_value_only_for_unmasked_unhidden_fields(): void
    {
        $decider = new FieldPolicyDecider([
            'payload.summary' => 'readonly',
            'payload.budget_amount' => 'masked',
        ]);
        $ancestry = new SingleAncestry(self::CLUSTER_ID, self::FACILITY_ID);
        $handler = new GetAuthorizedWorkRecordHandler($decider, $ancestry);

        $result = $handler->handle(
            ['user_id' => self::PRINCIPAL_ID, 'facility_id' => self::FACILITY_ID],
            self::RECORD_ID,
        );

        $this->assertNotNull($result);
        $this->assertSame('public summary', $result['payload']['summary']);
        $this->assertSame('***', $result['payload']['budget_amount']);
    }
}

final class FieldPolicyDecider implements DecideAccess
{
    /** @param array<string, string> $overrideAccess */
    public function __construct(private array $overrideAccess = [])
    {
    }

    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        $defaultAccess = [
            'payload.summary' => 'readonly',
            'payload.budget_amount' => 'masked',
            'payload.reviewer_note' => 'editable',
            'payload.internal_memo' => 'hidden',
        ];

        return new AccessDecision(
            decision: 'allow',
            action: $capability,
            resourceType: 'work_record',
            reasonCodes: ['focused_field_masking_test'],
            policyVersion: 'rbac-abac-v2',
            factsVersion: $facts === null ? 'test' : $facts->factsVersion,
            classification: $facts === null ? 'confidential' : $facts->classification,
            decisionId: '0197f0e0-0000-7000-8000-000000000aaa',
            allowedActions: ['read'],
            fieldAccess: $this->overrideAccess === [] ? $defaultAccess : array_merge($defaultAccess, $this->overrideAccess),
        );
    }
}

final class SingleAncestry implements ResolveOrganizationScopeAncestry
{
    public function __construct(
        private readonly string $clusterId,
        private readonly string $facilityId,
    ) {}

    public function ancestry(string $scopeType, string $scopeId): ?array
    {
        return ['cluster_id' => $this->clusterId, 'facility_id' => $this->facilityId, 'unit_id' => null];
    }
}
