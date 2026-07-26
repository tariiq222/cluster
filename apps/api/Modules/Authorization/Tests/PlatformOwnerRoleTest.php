<?php

namespace Modules\Authorization\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\CapabilityCatalog;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Authorization\Features\OperationsOffice\BootstrapOperationsOffice;
use Modules\Authorization\Features\OperationsOffice\OperationsOfficeRoleCatalog;
use Modules\Authorization\Infrastructure\RbacAbacDecideAccess;
use Tests\TestCase;

final class PlatformOwnerRoleTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = '0197f0e0-0000-7000-8000-000000000001';

    private const CLUSTER = '0197f0e0-0000-7000-8000-000000000101';

    // Platform owner grants the complete capability catalog and rides above
    // active explicit denies by design. The engine still checks unknown
    // capabilities before applying that documented exception.
    public function test_platform_owner_role_tracks_the_complete_capability_catalog(): void
    {
        $this->app->make(BootstrapOperationsOffice::class)->bootstrap(self::OWNER, self::CLUSTER);

        $roleId = DB::table('roles')->where('code', OperationsOfficeRoleCatalog::PLATFORM_OWNER_ROLE)->value('id');
        $granted = DB::table('role_capabilities')
            ->join('capabilities', 'capabilities.id', '=', 'role_capabilities.capability_id')
            ->where('role_capabilities.role_id', $roleId)
            ->where('role_capabilities.effect', 'allow')
            ->orderBy('capabilities.capability_code')
            ->pluck('capabilities.capability_code')
            ->all();
        $expected = CapabilityCatalog::all();
        sort($expected);

        $this->assertSame($expected, $granted);
        $this->assertDatabaseHas('role_assignments', [
            'user_id' => self::OWNER,
            'role_id' => $roleId,
            'scope_type' => 'cluster',
            'scope_id' => self::CLUSTER,
            'status' => 'active',
        ]);
    }

    public function test_platform_owner_bypasses_explicit_denies(): void
    {
        $this->app->make(BootstrapOperationsOffice::class)->bootstrap(self::OWNER, self::CLUSTER);
        $engine = $this->app->make(RbacAbacDecideAccess::class);
        $facts = new RecordFacts(
            ownerFacilityId: null,
            resourceType: 'workflow_version',
            classification: 'internal',
            recordId: '0197f0e0-0000-7000-8000-000000000201',
            clusterId: self::CLUSTER,
        );

        $allowed = $engine->decide($this->actor(), 'workflow.approve', $facts);
        $this->assertTrue($allowed->isAllowed());

        DB::table('explicit_denies')->insert([
            'id' => Str::uuid7()->toString(),
            'user_id' => self::OWNER,
            'capability_code' => 'workflow.approve',
            'classification' => null,
            'organization_unit_id' => null,
            'resource_pattern' => 'workflow_version',
            'reason' => 'Security hold',
            'issued_by_user_id' => self::OWNER,
            'issued_at' => now()->subMinute(),
            'expires_at' => null,
            'revocable' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $override = $engine->decide($this->actor(), 'workflow.approve', $facts);
        $this->assertTrue($override->isAllowed());
        $this->assertContains('platform_owner_super_admin_override', $override->reasonCodes);
        $this->assertDatabaseHas('access_decisions', [
            'actor_user_id' => self::OWNER,
            'action' => 'workflow.approve',
            'decision' => 'allow',
        ]);
    }

    public function test_platform_owner_does_not_bypass_unknown_capability(): void
    {
        $this->app->make(BootstrapOperationsOffice::class)->bootstrap(self::OWNER, self::CLUSTER);

        $decision = $this->app->make(RbacAbacDecideAccess::class)->decide(
            $this->actor(),
            'work_record.unknown',
            null,
        );

        $this->assertSame('deny', $decision->decision);
        $this->assertContains('capability_not_supported', $decision->reasonCodes);
    }

    // Capability-catalog validation remains fail closed and precedes the
    // documented platform-owner override. Once the capability is known, the
    // owner rides above record-facts, classification, and explicit-deny checks.
    // test_platform_owner_bypasses_explicit_denies pins that concession.
    /** @return array{user_id: string, organization_unit_ids: list<string>, correlation_id: string} */
    private function actor(): array
    {
        return [
            'user_id' => self::OWNER,
            'organization_unit_ids' => [],
            'correlation_id' => Str::uuid7()->toString(),
        ];
    }
}
