<?php

namespace Modules\Organization\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Organization\Features\OrganizationUnit\Handler\OrganizationUnitHandler;
use Modules\Organization\Features\Position\Handler\PositionHandler;
use Tests\TestCase;

class OrganizationTreeHttpAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000401';

    public function test_unit_is_created_read_listed_and_replayed_under_a_facility(): void
    {
        $token = $this->loginToken();
        $clusterId = $this->createCluster($token);
        $facilityId = $this->createFacility($token, $clusterId, 'HOSPITAL-01');
        $body = [
            'cluster_id' => $clusterId,
            'parent_id' => $facilityId,
            'type_code' => 'sector',
            'code' => 'MEDICAL',
            'name' => 'القطاع الطبي',
        ];

        $first = $this->withToken($token)
            ->postJson('/api/v1/organization/units', $body, $this->writeHeaders('unit-create'))
            ->assertCreated()
            ->assertHeader('ETag', '"1"')
            ->assertJsonPath('data.parent_type', 'facility')
            ->assertJsonPath('data.parent_id', $facilityId)
            ->assertJsonPath('data.type_code', 'sector')
            ->assertJsonPath('data.depth', 2);
        $unitId = $first->json('data.id');
        $this->assertIsString($unitId);

        $replay = $this->withToken($token)
            ->postJson('/api/v1/organization/units', $body, $this->writeHeaders('unit-create'))
            ->assertCreated();
        $this->assertSame($unitId, $replay->json('data.id'));

        $this->withToken($token)
            ->getJson('/api/v1/organization/units/not-a-uuid', $this->headers())
            ->assertBadRequest();
        $this->withToken($token)
            ->getJson('/api/v1/organization/units/018f6f7d-0c00-7000-8000-000000000499', $this->headers())
            ->assertNotFound();

        $this->withToken($token)
            ->getJson("/api/v1/organization/units/{$unitId}", $this->headers())
            ->assertOk()
            ->assertHeader('ETag', '"1"')
            ->assertJsonPath('data.path_cache', "/{$clusterId}/{$facilityId}/{$unitId}");
        $createdEvent = json_decode((string) DB::table('outbox_events')->where('aggregate_id', $unitId)->value('cloud_event'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($unitId, $createdEvent['data']['organization_unit']['id']);
        $this->assertEventContext($createdEvent, $clusterId);
        $this->withToken($token)
            ->getJson('/api/v1/organization/units?parent_id='.$facilityId, $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $unitId);

        $this->assertDatabaseHas('outbox_events', [
            'aggregate_id' => $unitId,
            'event_type' => 'com.cluster.organization.organizationunitcreated.v1',
        ]);
        $this->createUnit($token, $clusterId, $facilityId, 'department', 'ADMIN', 'الإدارة');
        $this->createUnit($token, $clusterId, $facilityId, 'unit', 'QUALITY', 'الجودة');
        $page = $this->withToken($token)
            ->getJson('/api/v1/organization/units?limit=2&parent_id='.$facilityId, $this->headers())
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertHeader('Link');
        $cursor = $page->json('next_cursor');
        $this->assertIsString($cursor);
        $this->withToken($token)
            ->getJson('/api/v1/organization/units?limit=2&parent_id='.$facilityId.'&cursor='.rawurlencode($cursor), $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('next_cursor', null);
        $this->withToken($token)
            ->getJson('/api/v1/organization/units?limit=2&parent_id='.$clusterId.'&cursor='.rawurlencode($cursor), $this->headers())
            ->assertBadRequest();

        $this->withToken($token)
            ->patchJson("/api/v1/organization/units/{$unitId}", ['name' => 'القطاع الطبي المحدث'], $this->patchHeaders('"1"'))
            ->assertOk()
            ->assertHeader('ETag', '"2"');
        $snapshot = $this->withToken($token)
            ->postJson('/api/v1/organization/units', $body, $this->writeHeaders('unit-create'))
            ->assertCreated()
            ->assertHeader('ETag', '"1"')
            ->assertJsonPath('data.name_ar', 'القطاع الطبي')
            ->assertJsonPath('data.lock_version', 1);
        $this->assertSame($unitId, $snapshot->json('data.id'));
        $this->assertDatabaseCount('organization_units', 3);
        $this->assertDatabaseCount('organization_idempotency_keys', 5);
    }

    public function test_unit_move_updates_descendant_paths_and_rejects_cycles_and_stale_versions(): void
    {
        $token = $this->loginToken();
        $clusterId = $this->createCluster($token);
        $facilityId = $this->createFacility($token, $clusterId, 'HOSPITAL-01');
        $sectorId = $this->createUnit($token, $clusterId, $facilityId, 'sector', 'SECTOR', 'القطاع');
        $departmentId = $this->createUnit($token, $clusterId, $sectorId, 'department', 'DEPT', 'الإدارة');
        $sectionId = $this->createUnit($token, $clusterId, $departmentId, 'section', 'SECTION', 'القسم');
        $siblingId = $this->createUnit($token, $clusterId, $sectorId, 'department', 'SIBLING', 'إدارة أخرى');

        $this->withToken($token)
            ->patchJson("/api/v1/organization/units/{$departmentId}", ['name' => 'بلا شرط'], $this->headers())
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-if-match');
        $this->withToken($token)
            ->patchJson("/api/v1/organization/units/{$departmentId}", ['name' => 'نوع خاطئ'], [
                ...$this->headers(),
                'If-Match' => '"1"',
            ])
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-content-type');

        $this->withToken($token)
            ->patchJson("/api/v1/organization/units/{$sectorId}", ['parent_id' => $departmentId], $this->patchHeaders('"1"'))
            ->assertConflict()
            ->assertJsonPath('type', 'https://cluster.example/problems/organization-unit-cycle');

        $this->withToken($token)
            ->patchJson("/api/v1/organization/units/{$departmentId}", [
                'parent_id' => $siblingId,
                'reason' => 'إعادة تنظيم',
            ], $this->patchHeaders('"1"'))
            ->assertOk()
            ->assertHeader('ETag', '"2"')
            ->assertJsonPath('data.parent_id', $siblingId)
            ->assertJsonPath('data.depth', 4);

        $departmentPath = "/{$clusterId}/{$facilityId}/{$sectorId}/{$siblingId}/{$departmentId}";
        $this->assertDatabaseHas('organization_units', [
            'id' => $departmentId,
            'path_cache' => $departmentPath,
            'depth' => 4,
            'lock_version' => 2,
        ]);
        $this->assertDatabaseHas('organization_units', [
            'id' => $sectionId,
            'path_cache' => "{$departmentPath}/{$sectionId}",
            'depth' => 5,
        ]);
        $this->withToken($token)
            ->patchJson("/api/v1/organization/units/{$departmentId}", ['name' => 'كتابة متأخرة'], $this->patchHeaders('"1"'))
            ->assertStatus(412);
        $this->assertDatabaseHas('outbox_events', [
            'aggregate_id' => $departmentId,
            'event_type' => 'com.cluster.organization.organizationunitmoved.v1',
        ]);
        $movedEvent = json_decode((string) DB::table('outbox_events')
            ->where('aggregate_id', $departmentId)
            ->where('event_type', 'com.cluster.organization.organizationunitmoved.v1')
            ->value('cloud_event'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($sectorId, $movedEvent['data']['previous_parent_id']);
        $this->assertEventContext($movedEvent, $clusterId);
    }

    public function test_unit_lifecycle_is_guarded_and_archived_is_terminal(): void
    {
        $token = $this->loginToken();
        $clusterId = $this->createCluster($token);
        $unitId = $this->createUnit($token, $clusterId, null, 'sector', 'SECTOR', 'القطاع');
        $childId = $this->createUnit($token, $clusterId, $unitId, 'department', 'CHILD', 'إدارة تابعة');

        $this->withToken($token)
            ->patchJson("/api/v1/organization/units/{$unitId}", [
                'status' => 'archived',
                'reason' => 'قفز غير مسموح',
            ], $this->patchHeaders('"1"'))
            ->assertConflict();
        $this->withToken($token)
            ->patchJson("/api/v1/organization/units/{$unitId}", [
                'status' => 'inactive',
                'reason' => 'إيقاف',
            ], $this->patchHeaders('"1"'))
            ->assertOk()
            ->assertHeader('ETag', '"2"');
        $this->withToken($token)
            ->patchJson("/api/v1/organization/units/{$unitId}", [
                'status' => 'archived',
                'reason' => 'أرشفة نهائية',
            ], $this->patchHeaders('"2"'))
            ->assertOk()
            ->assertHeader('ETag', '"3"')
            ->assertJsonPath('data.status', 'archived');
        $this->withToken($token)
            ->patchJson("/api/v1/organization/units/{$unitId}", ['status' => 'active'], $this->patchHeaders('"3"'))
            ->assertConflict();
        $this->assertDatabaseHas('organization_units', ['id' => $childId, 'lock_version' => 1]);
        $this->assertSame(2, DB::table('outbox_events')
            ->where('aggregate_id', $unitId)
            ->where('event_type', 'com.cluster.organization.organizationunitarchived.v1')
            ->count());
    }

    public function test_positions_require_valid_units_and_prevent_manager_cycles(): void
    {
        $token = $this->loginToken();
        $clusterId = $this->createCluster($token);
        $unitId = $this->createUnit($token, $clusterId, null, 'department', 'HR', 'الموارد البشرية');
        $otherUnitId = $this->createUnit($token, $clusterId, null, 'department', 'FIN', 'المالية');
        $managerId = $this->createPosition($token, $unitId, 'DIRECTOR', 'المدير', null);
        $employeeId = $this->createPosition($token, $unitId, 'SPECIALIST', 'أخصائي', $managerId);
        $this->createPosition($token, $unitId, 'ASSISTANT', 'مساعد', $managerId);
        $createdEvent = json_decode((string) DB::table('outbox_events')->where('aggregate_id', $employeeId)->value('cloud_event'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($employeeId, $createdEvent['data']['position']['id']);
        $this->assertEventContext($createdEvent, $clusterId);

        $this->withToken($token)
            ->getJson('/api/v1/organization/positions/not-a-uuid', $this->headers())
            ->assertBadRequest();
        $this->withToken($token)
            ->getJson('/api/v1/organization/positions/018f6f7d-0c00-7000-8000-000000000499', $this->headers())
            ->assertNotFound();

        $this->withToken($token)
            ->getJson("/api/v1/organization/positions/{$employeeId}", $this->headers())
            ->assertOk()
            ->assertHeader('ETag', '"1"')
            ->assertJsonPath('data.manager_position_id', $managerId);
        $positionPage = $this->withToken($token)
            ->getJson('/api/v1/organization/positions?limit=2&unit_id='.$unitId, $this->headers())
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertHeader('Link');
        $positionCursor = $positionPage->json('next_cursor');
        $this->assertIsString($positionCursor);
        $this->withToken($token)
            ->getJson('/api/v1/organization/positions?limit=2&unit_id='.$unitId.'&cursor='.rawurlencode($positionCursor), $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'items');
        $this->withToken($token)
            ->getJson('/api/v1/organization/positions?limit=2&unit_id='.$otherUnitId.'&cursor='.rawurlencode($positionCursor), $this->headers())
            ->assertBadRequest();

        $this->withToken($token)
            ->patchJson("/api/v1/organization/positions/{$managerId}", ['manager_position_id' => $employeeId], $this->patchHeaders('"1"'))
            ->assertConflict()
            ->assertJsonPath('type', 'https://cluster.example/problems/position-manager-cycle');
        $this->withToken($token)
            ->patchJson("/api/v1/organization/positions/{$employeeId}", ['title' => 'بلا شرط'], $this->headers())
            ->assertBadRequest();
        $this->withToken($token)
            ->patchJson("/api/v1/organization/positions/{$employeeId}", ['title' => 'نوع خاطئ'], [
                ...$this->headers(),
                'If-Match' => '"1"',
            ])
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-content-type');
        $this->withToken($token)
            ->patchJson("/api/v1/organization/positions/{$employeeId}", [
                'organization_unit_id' => $otherUnitId,
                'title' => 'أخصائي أول',
                'manager_position_id' => null,
            ], $this->patchHeaders('"1"'))
            ->assertOk()
            ->assertHeader('ETag', '"2"')
            ->assertJsonPath('data.organization_unit_id', $otherUnitId)
            ->assertJsonPath('data.title_ar', 'أخصائي أول')
            ->assertJsonPath('data.manager_position_id', null);
        $updatedEvent = json_decode((string) DB::table('outbox_events')
            ->where('aggregate_id', $employeeId)
            ->where('event_type', 'com.cluster.organization.positionupdated.v1')
            ->value('cloud_event'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertEventContext($updatedEvent, $clusterId);
        $this->withToken($token)
            ->postJson('/api/v1/organization/positions', [
                'organization_unit_id' => $unitId,
                'code' => 'SPECIALIST',
                'title' => 'أخصائي',
                'manager_position_id' => $managerId,
            ], $this->writeHeaders('position-SPECIALIST'))
            ->assertCreated()
            ->assertHeader('ETag', '"1"')
            ->assertJsonPath('data.title_ar', 'أخصائي')
            ->assertJsonPath('data.manager_position_id', $managerId)
            ->assertJsonPath('data.lock_version', 1);
        $this->withToken($token)
            ->patchJson("/api/v1/organization/positions/{$employeeId}", ['title' => 'قديم'], $this->patchHeaders('"1"'))
            ->assertStatus(412);

        $this->withToken($token)->postJson('/api/v1/organization/positions', [
            'organization_unit_id' => '018f6f7d-0c00-7000-8000-000000000499',
            'code' => 'INVALID',
            'title' => 'منصب غير صالح',
        ], $this->writeHeaders('invalid-position'))->assertBadRequest();
        $this->assertDatabaseCount('positions', 3);
    }

    public function test_position_manager_walk_rejects_chains_deeper_than_thirty_two_hops(): void
    {
        $token = $this->loginToken();
        $clusterId = $this->createCluster($token);
        $unitId = $this->createUnit($token, $clusterId, null, 'department', 'DEEP', 'سلسلة إدارية عميقة');
        $now = now();
        $managerId = null;

        for ($hop = 0; $hop < 34; $hop++) {
            $positionId = sprintf('018f6f7d-0c00-7%03x-8000-%012x', $hop, $hop + 1);
            DB::table('positions')->insert([
                'id' => $positionId,
                'organization_unit_id' => $unitId,
                'code' => 'DEEP-'.$hop,
                'title_ar' => 'مدير '.$hop,
                'job_title_id' => null,
                'manager_position_id' => $managerId,
                'is_active' => true,
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $managerId = $positionId;
        }

        $this->withToken($token)
            ->postJson('/api/v1/organization/positions', [
                'organization_unit_id' => $unitId,
                'code' => 'DEPTH-LIMITED',
                'title' => 'يتجاوز الحد',
                'manager_position_id' => $managerId,
            ], $this->writeHeaders('position-depth-limited'))
            ->assertConflict()
            ->assertJsonPath('type', 'https://cluster.example/problems/position-manager-cycle');

        $this->assertDatabaseMissing('positions', ['code' => 'DEPTH-LIMITED']);
    }

    public function test_tree_writes_fail_closed_and_roll_back_with_outbox(): void
    {
        $admin = $this->loginToken();
        $other = $this->loginToken('fixture-account-b', 'fixture-password-b');

        $this->getJson('/api/v1/organization/units/018f6f7d-0c00-7000-8000-000000000499', $this->headers())
            ->assertUnauthorized();
        $this->getJson('/api/v1/organization/positions/018f6f7d-0c00-7000-8000-000000000499', $this->headers())
            ->assertUnauthorized();

        $this->postJson('/api/v1/organization/units', [
            'cluster_id' => '018f6f7d-0c00-7000-8000-000000000499',
            'type_code' => 'sector',
            'code' => 'UNAUTH',
            'name' => 'غير مصادق',
        ], $this->writeHeaders('unauth-unit'))->assertUnauthorized();
        $clusterId = $this->createCluster($admin);
        $this->withToken($other)->postJson('/api/v1/organization/units', [
            'cluster_id' => $clusterId,
            'type_code' => 'sector',
            'code' => 'DENIED',
            'name' => 'مرفوض',
        ], $this->writeHeaders('denied-unit'))->assertForbidden();
        $this->withToken($admin)->postJson('/api/v1/organization/units', [
            'cluster_id' => $clusterId,
            'parent_id' => '018f6f7d-0c00-7000-8000-000000000498',
            'type_code' => 'sector',
            'code' => 'INVALID',
            'name' => 'والد غير صالح',
        ], $this->writeHeaders('invalid-unit-parent'))->assertBadRequest();

        $unitId = $this->createUnit($admin, $clusterId, null, 'sector', 'ROLLBACK', 'قبل اللف');
        $positionId = $this->createPosition($admin, $unitId, 'ROLLBACK', 'قبل اللف', null);
        $this->withToken($other)
            ->getJson("/api/v1/organization/units/{$unitId}", $this->headers())
            ->assertForbidden();
        $this->withToken($other)
            ->patchJson("/api/v1/organization/units/{$unitId}", ['name' => 'مرفوض'], $this->patchHeaders('"1"'))
            ->assertForbidden();
        $this->withToken($other)
            ->getJson("/api/v1/organization/positions/{$positionId}", $this->headers())
            ->assertForbidden();
        $this->withToken($other)
            ->patchJson("/api/v1/organization/positions/{$positionId}", ['title' => 'مرفوض'], $this->patchHeaders('"1"'))
            ->assertForbidden();
        $eventId = (string) DB::table('outbox_events')->value('event_id');

        try {
            $this->app->make(OrganizationUnitHandler::class)->update(
                $unitId,
                1,
                ['name' => 'بعد اللف'],
                fn (array $unit, string $previousParentId, string $previousStatus): array => $this->duplicateEvent($eventId, 'organizationunitupdated', ['unit' => $unit, 'previous_parent_id' => $previousParentId, 'previous_status' => $previousStatus]),
            );
            $this->fail('The duplicate unit event must fail the transaction.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', (string) $exception->getCode());
        }
        try {
            $this->app->make(PositionHandler::class)->update(
                $positionId,
                1,
                ['title' => 'بعد اللف'],
                fn (array $position, string $clusterId): array => $this->duplicateEvent($eventId, 'positionupdated', ['position' => $position, 'cluster_id' => $clusterId]),
            );
            $this->fail('The duplicate position event must fail the transaction.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', (string) $exception->getCode());
        }

        $this->assertDatabaseHas('organization_units', ['id' => $unitId, 'name_ar' => 'قبل اللف', 'lock_version' => 1]);
        $this->assertDatabaseHas('positions', ['id' => $positionId, 'title_ar' => 'قبل اللف', 'lock_version' => 1]);
    }

    private function createCluster(string $token): string
    {
        return (string) $this->withToken($token)->postJson('/api/v1/organization/cluster', [
            'code' => 'THC3',
            'name' => 'التجمع الصحي الثالث',
        ], $this->writeHeaders('tree-cluster'))->assertCreated()->json('data.id');
    }

    private function createFacility(string $token, string $clusterId, string $code): string
    {
        return (string) $this->withToken($token)->postJson('/api/v1/organization/facilities', [
            'cluster_id' => $clusterId,
            'type_code' => 'hospital',
            'code' => $code,
            'name' => 'مستشفى التجمع',
        ], $this->writeHeaders('facility-'.$code))->assertCreated()->json('data.id');
    }

    private function createUnit(
        string $token,
        string $clusterId,
        ?string $parentId,
        string $typeCode,
        string $code,
        string $name,
    ): string {
        $body = array_filter([
            'cluster_id' => $clusterId,
            'parent_id' => $parentId,
            'type_code' => $typeCode,
            'code' => $code,
            'name' => $name,
        ], fn (mixed $value): bool => $value !== null);

        return (string) $this->withToken($token)
            ->postJson('/api/v1/organization/units', $body, $this->writeHeaders('unit-'.$code))
            ->assertCreated()
            ->json('data.id');
    }

    private function createPosition(string $token, string $unitId, string $code, string $title, ?string $managerId): string
    {
        $body = array_filter([
            'organization_unit_id' => $unitId,
            'code' => $code,
            'title' => $title,
            'manager_position_id' => $managerId,
        ], fn (mixed $value): bool => $value !== null);

        return (string) $this->withToken($token)
            ->postJson('/api/v1/organization/positions', $body, $this->writeHeaders('position-'.$code))
            ->assertCreated()
            ->json('data.id');
    }

    /** @param array<string, mixed> $data */
    private function duplicateEvent(string $eventId, string $name, array $data): array
    {
        return [
            'id' => $eventId,
            'type' => "com.cluster.organization.{$name}.v1",
            'time' => '2026-07-18T00:00:00.000Z',
            'data' => $data,
        ];
    }

    /** @param array<string, mixed> $event */
    private function assertEventContext(array $event, string $clusterId): void
    {
        $this->assertSame(self::CORRELATION_ID, $event['correlationid']);
        $this->assertSame('internal', $event['data']['classification']);
        $context = $event['data']['access_context'];
        $this->assertSame('018f6f7d-0c00-7000-8000-000000000021', $context['subject_id']);
        $this->assertSame($clusterId, $context['tenant_id']);
        $this->assertSame([], $context['organization_unit_ids']);
        $this->assertSame(['bootstrap_admin'], $context['roles']);
        $this->assertSame('internal', $context['clearance']);
        $this->assertFalse($context['break_glass']);
        $this->assertSame(self::CORRELATION_ID, $context['correlation_id']);
    }

    private function loginToken(string $username = 'fixture-account-a', string $password = 'fixture-password-a'): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'username' => $username,
            'password' => $password,
        ], $this->headers())->assertOk()->json('data.access_token');
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-Correlation-ID' => self::CORRELATION_ID];
    }

    /** @return array<string, string> */
    private function writeHeaders(string $key): array
    {
        return [...$this->headers(), 'Idempotency-Key' => $key];
    }

    /** @return array<string, string> */
    private function patchHeaders(string $etag): array
    {
        return [
            ...$this->headers(),
            'If-Match' => $etag,
            'Content-Type' => 'application/merge-patch+json',
        ];
    }
}
