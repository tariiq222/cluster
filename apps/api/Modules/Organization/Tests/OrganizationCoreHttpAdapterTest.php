<?php

namespace Modules\Organization\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Organization\Domain\Facility;
use Modules\Organization\Features\CreateFacility\Handler\CreateFacilityHandler;
use Modules\Organization\Features\UpdateCluster\Handler\UpdateClusterHandler;
use Modules\Organization\Features\UpdateFacility\Handler\UpdateFacilityHandler;
use Tests\TestCase;

class OrganizationCoreHttpAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000301';

    public function test_cluster_is_created_once_with_stable_idempotency_replay(): void
    {
        $token = $this->loginToken();
        $body = ['code' => 'THC3', 'name' => 'التجمع الصحي الثالث'];

        $first = $this->withToken($token)
            ->postJson('/api/v1/organization/cluster', $body, $this->writeHeaders('cluster-create'))
            ->assertCreated()
            ->assertHeader('X-Correlation-ID', self::CORRELATION_ID)
            ->assertHeader('ETag', '"1"')
            ->assertJsonPath('data.code', 'THC3')
            ->assertJsonPath('data.name_ar', 'التجمع الصحي الثالث');

        $replay = $this->withToken($token)
            ->postJson('/api/v1/organization/cluster', $body, $this->writeHeaders('cluster-create'))
            ->assertCreated();

        $this->assertSame($first->json('data.id'), $replay->json('data.id'));

        $this->withToken($token)->postJson('/api/v1/organization/cluster', [
            'code' => 'OTHER',
            'name' => 'تجمع آخر',
        ], $this->writeHeaders('cluster-create'))->assertConflict()
            ->assertJsonPath('type', 'https://cluster.example/problems/idempotency-conflict');

        $this->withToken($token)->postJson('/api/v1/organization/cluster', [
            'code' => 'OTHER',
            'name' => 'تجمع آخر',
        ], $this->writeHeaders('second-cluster'))->assertConflict()
            ->assertJsonPath('type', 'https://cluster.example/problems/cluster-already-exists');

        $this->withToken($token)
            ->getJson('/api/v1/organization/cluster', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertDatabaseCount('clusters', 1);
        $this->assertDatabaseCount('outbox_events', 1);
        $this->assertDatabaseCount('organization_idempotency_keys', 1);
    }

    public function test_valid_facility_is_created_under_the_singleton_cluster(): void
    {
        $token = $this->loginToken();
        $clusterId = $this->createCluster($token);

        $body = [
            'cluster_id' => $clusterId,
            'type_code' => 'hospital',
            'code' => 'HOSPITAL-01',
            'name' => 'مستشفى التجمع',
        ];
        $facility = $this->withToken($token)->postJson('/api/v1/organization/facilities', $body, $this->writeHeaders('facility-create'))->assertCreated()
            ->assertHeader('ETag', '"1"')
            ->assertJsonPath('data.cluster_id', $clusterId)
            ->assertJsonPath('data.type_code', 'hospital')
            ->assertJsonPath('data.status', 'active');

        $facilityId = $facility->json('data.id');
        $this->assertIsString($facilityId);
        $replay = $this->withToken($token)
            ->postJson('/api/v1/organization/facilities', $body, $this->writeHeaders('facility-create'))
            ->assertCreated();
        $this->assertSame($facilityId, $replay->json('data.id'));

        $this->withToken($token)->postJson('/api/v1/organization/facilities', [
            ...$body,
            'name' => 'اسم مختلف',
        ], $this->writeHeaders('facility-create'))->assertConflict()
            ->assertJsonPath('type', 'https://cluster.example/problems/idempotency-conflict');
        $this->withToken($token)
            ->postJson('/api/v1/organization/facilities', $body, $this->writeHeaders('duplicate-facility'))
            ->assertConflict()
            ->assertJsonPath('type', 'https://cluster.example/problems/facility-already-exists');

        $this->assertDatabaseHas('facilities', [
            'id' => $facilityId,
            'cluster_id' => $clusterId,
            'code' => 'HOSPITAL-01',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'aggregate_id' => $facilityId,
            'event_type' => 'com.cluster.organization.facilitycreated.v1',
        ]);
        $event = json_decode((string) DB::table('outbox_events')->where('aggregate_id', $facilityId)->value('cloud_event'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($facilityId, $event['data']['facility']['id']);
        $this->assertSame(self::CORRELATION_ID, $event['correlationid']);
        $this->assertDatabaseCount('facilities', 1);
        $this->assertDatabaseCount('outbox_events', 2);
        $this->assertDatabaseCount('organization_idempotency_keys', 2);

        $this->withToken($token)
            ->getJson('/api/v1/organization/facilities', $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $facilityId);
    }

    public function test_cluster_profile_update_requires_current_strong_etag(): void
    {
        $token = $this->loginToken();
        $clusterId = $this->createCluster($token);

        $this->withToken($token)
            ->patchJson('/api/v1/organization/cluster', ['name' => 'اسم بلا شرط'], $this->headers())
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-if-match');

        $this->withToken($token)
            ->patchJson('/api/v1/organization/cluster', ['name' => 'اسم قديم'], $this->patchHeaders('"2"'))
            ->assertStatus(412)
            ->assertJsonPath('type', 'https://cluster.example/problems/precondition-failed');

        $this->withToken($token)
            ->patchJson('/api/v1/organization/cluster', [
                'name' => 'تجمع صحي محدث',
                'reason' => 'تحديث الاسم الرسمي',
            ], $this->patchHeaders('"1"'))
            ->assertOk()
            ->assertHeader('ETag', '"2"')
            ->assertJsonPath('data.id', $clusterId)
            ->assertJsonPath('data.name_ar', 'تجمع صحي محدث')
            ->assertJsonPath('data.lock_version', 2);

        $this->assertDatabaseHas('clusters', [
            'id' => $clusterId,
            'name_ar' => 'تجمع صحي محدث',
            'lock_version' => 2,
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'aggregate_id' => $clusterId,
            'event_type' => 'com.cluster.organization.clusterupdated.v1',
        ]);

        $this->withToken($token)
            ->patchJson('/api/v1/organization/cluster', ['name' => 'كتابة متأخرة'], $this->patchHeaders('"1"'))
            ->assertStatus(412);

        $this->assertDatabaseMissing('clusters', ['name_ar' => 'كتابة متأخرة']);
        $this->assertDatabaseCount('outbox_events', 2);
    }

    public function test_facility_show_update_and_archive_follow_optimistic_concurrency(): void
    {
        $token = $this->loginToken();
        $clusterId = $this->createCluster($token);
        $this->withToken($token)
            ->getJson('/api/v1/organization/facilities/018f6f7d-0c00-7000-8000-000000000399', $this->headers())
            ->assertNotFound()
            ->assertJsonPath('type', 'https://cluster.example/problems/facility-not-found');
        $this->withToken($token)
            ->getJson('/api/v1/organization/facilities/not-a-uuid', $this->headers())
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-facility-id');
        $facilityId = $this->createFacility($token, $clusterId);

        $this->withToken($token)
            ->patchJson("/api/v1/organization/facilities/{$facilityId}", ['name' => 'بلا شرط'], $this->headers())
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-if-match');
        $this->withToken($token)
            ->patchJson("/api/v1/organization/facilities/{$facilityId}", ['name' => 'نوع محتوى خاطئ'], [
                ...$this->headers(),
                'If-Match' => '"1"',
            ])
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-content-type');
        $this->withToken($token)
            ->patchJson("/api/v1/organization/facilities/{$facilityId}", ['unknown' => true], $this->patchHeaders('"1"'))
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-facility');

        $this->withToken($token)
            ->getJson("/api/v1/organization/facilities/{$facilityId}", $this->headers())
            ->assertOk()
            ->assertHeader('ETag', '"1"')
            ->assertJsonPath('data.id', $facilityId)
            ->assertJsonPath('data.type_code', 'hospital');

        $this->withToken($token)
            ->patchJson("/api/v1/organization/facilities/{$facilityId}", ['name' => 'مستشفى محدث'], $this->patchHeaders('"1"'))
            ->assertOk()
            ->assertHeader('ETag', '"2"')
            ->assertJsonPath('data.name_ar', 'مستشفى محدث')
            ->assertJsonPath('data.lock_version', 2);

        $this->withToken($token)
            ->patchJson("/api/v1/organization/facilities/{$facilityId}", ['name' => 'كتابة متأخرة'], $this->patchHeaders('"1"'))
            ->assertStatus(412);

        $this->withToken($token)
            ->patchJson("/api/v1/organization/facilities/{$facilityId}", [
                'status' => 'archived',
                'reason' => 'تجاوز الحالة الوسيطة',
            ], $this->patchHeaders('"2"'))
            ->assertConflict()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-facility-transition');

        $this->withToken($token)
            ->patchJson("/api/v1/organization/facilities/{$facilityId}", [
                'status' => 'inactive',
                'reason' => 'إيقاف المنشأة',
            ], $this->patchHeaders('"2"'))
            ->assertOk()
            ->assertHeader('ETag', '"3"')
            ->assertJsonPath('data.status', 'inactive');

        $this->withToken($token)
            ->patchJson("/api/v1/organization/facilities/{$facilityId}", [
                'name' => 'منشأة غير نشطة محدثة',
                'status' => 'inactive',
            ], $this->patchHeaders('"3"'))
            ->assertOk()
            ->assertHeader('ETag', '"4"')
            ->assertJsonPath('data.status', 'inactive');

        $this->withToken($token)
            ->patchJson("/api/v1/organization/facilities/{$facilityId}", [
                'status' => 'archived',
                'reason' => 'أرشفة نهائية',
            ], $this->patchHeaders('"4"'))
            ->assertOk()
            ->assertHeader('ETag', '"5"')
            ->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.lock_version', 5);

        $this->withToken($token)
            ->patchJson("/api/v1/organization/facilities/{$facilityId}", [
                'status' => 'active',
                'reason' => 'محاولة إعادة سجل نهائي',
            ], $this->patchHeaders('"5"'))
            ->assertConflict();

        $this->assertDatabaseHas('facilities', [
            'id' => $facilityId,
            'name_ar' => 'منشأة غير نشطة محدثة',
            'status' => 'archived',
            'lock_version' => 5,
        ]);
        $this->assertSame(2, DB::table('outbox_events')
            ->where('aggregate_id', $facilityId)
            ->where('event_type', 'com.cluster.organization.facilityupdated.v1')
            ->count());
        $this->assertSame(2, DB::table('outbox_events')
            ->where('aggregate_id', $facilityId)
            ->where('event_type', 'com.cluster.organization.facilityarchived.v1')
            ->count());
        $this->assertDatabaseCount('outbox_events', 6);
    }

    public function test_invalid_facility_type_or_parent_writes_no_partial_facility(): void
    {
        $token = $this->loginToken();
        $clusterId = $this->createCluster($token);

        $this->withToken($token)->postJson('/api/v1/organization/facilities', [
            'cluster_id' => $clusterId,
            'type_code' => 'unknown-type',
            'code' => 'INVALID-TYPE',
            'name' => 'منشأة غير صالحة',
        ], $this->writeHeaders('invalid-type'))->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-facility');

        $this->withToken($token)->postJson('/api/v1/organization/facilities', [
            'cluster_id' => '018f6f7d-0c00-7000-8000-000000000399',
            'type_code' => 'hospital',
            'code' => 'INVALID-PARENT',
            'name' => 'منشأة بلا تجمع',
        ], $this->writeHeaders('invalid-parent'))->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-facility');

        $this->assertDatabaseCount('facilities', 0);
        $this->assertDatabaseCount('outbox_events', 1);
        $this->assertDatabaseCount('organization_idempotency_keys', 1);
    }

    public function test_facility_write_rolls_back_when_outbox_insert_fails(): void
    {
        $token = $this->loginToken();
        $clusterId = $this->createCluster($token);
        $eventId = (string) DB::table('outbox_events')->value('event_id');
        $facility = Facility::create(
            '0197f0e0-0000-7000-8000-000000000201',
            $clusterId,
            'hospital',
            'ROLLBACK-01',
            'منشأة يجب أن تلف',
            null,
        );

        try {
            $this->app->make(CreateFacilityHandler::class)->persist($facility, [
                'id' => $eventId,
                'type' => 'com.cluster.organization.facilitycreated.v1',
                'time' => '2026-07-18T00:00:00.000Z',
            ], [
                'principal_id' => '018f6f7d-0c00-7000-8000-000000000021',
                'operation' => 'createFacility',
                'key_hash' => hash('sha256', 'outbox-failure'),
                'request_hash' => hash('sha256', 'outbox-failure'),
            ]);
            $this->fail('The duplicate outbox event must fail the transaction.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', (string) $exception->getCode());
        }

        $this->assertDatabaseCount('facilities', 0);
        $this->assertDatabaseCount('organization_idempotency_keys', 1);
    }

    public function test_facility_update_rolls_back_when_outbox_insert_fails(): void
    {
        $token = $this->loginToken();
        $clusterId = $this->createCluster($token);
        $facilityId = $this->createFacility($token, $clusterId);
        $eventId = (string) DB::table('outbox_events')->value('event_id');

        try {
            $this->app->make(UpdateFacilityHandler::class)->update(
                $facilityId,
                1,
                ['name' => 'اسم يجب أن يلف'],
                fn (array $facility, string $previousStatus): array => [
                    'id' => $eventId,
                    'type' => 'com.cluster.organization.facilityupdated.v1',
                    'time' => '2026-07-18T00:00:00.000Z',
                    'data' => ['facility' => $facility, 'previous_status' => $previousStatus],
                ],
            );
            $this->fail('The duplicate outbox event must fail the transaction.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', (string) $exception->getCode());
        }

        $this->assertDatabaseHas('facilities', [
            'id' => $facilityId,
            'name_ar' => 'مستشفى التجمع',
            'lock_version' => 1,
        ]);
        $this->assertDatabaseCount('outbox_events', 2);
    }

    public function test_cluster_update_rolls_back_when_outbox_insert_fails(): void
    {
        $token = $this->loginToken();
        $clusterId = $this->createCluster($token);
        $eventId = (string) DB::table('outbox_events')->value('event_id');

        try {
            $this->app->make(UpdateClusterHandler::class)->update(
                1,
                ['name' => 'اسم يجب أن يلف'],
                fn (array $cluster): array => [
                    'id' => $eventId,
                    'type' => 'com.cluster.organization.clusterupdated.v1',
                    'time' => '2026-07-18T00:00:00.000Z',
                    'data' => ['cluster' => $cluster],
                ],
            );
            $this->fail('The duplicate outbox event must fail the transaction.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', (string) $exception->getCode());
        }

        $this->assertDatabaseHas('clusters', [
            'id' => $clusterId,
            'name_ar' => 'التجمع الصحي الثالث',
            'lock_version' => 1,
        ]);
        $this->assertDatabaseCount('outbox_events', 1);
    }

    public function test_requests_fail_closed_without_admin_bootstrap_access(): void
    {
        $admin = $this->loginToken();
        $otherFacilityUser = $this->loginToken('fixture-account-b', 'fixture-password-b');

        $this->postJson('/api/v1/organization/cluster', [
            'code' => 'THC3',
            'name' => 'التجمع الصحي الثالث',
        ], $this->writeHeaders('unauthenticated'))->assertUnauthorized();
        $this->getJson('/api/v1/organization/facilities/018f6f7d-0c00-7000-8000-000000000399', $this->headers())
            ->assertUnauthorized();

        $this->withToken($admin)->postJson('/api/v1/organization/cluster', [
            'code' => 'THC3',
            'name' => 'التجمع الصحي الثالث',
        ])->assertBadRequest();

        $this->withToken($otherFacilityUser)->postJson('/api/v1/organization/cluster', [
            'code' => 'THC3',
            'name' => 'التجمع الصحي الثالث',
        ], $this->writeHeaders('denied-bootstrap'))->assertForbidden();
        $this->withToken($otherFacilityUser)
            ->getJson('/api/v1/organization/facilities', $this->headers())
            ->assertForbidden();
        $this->withToken($otherFacilityUser)
            ->getJson('/api/v1/organization/facilities/018f6f7d-0c00-7000-8000-000000000399', $this->headers())
            ->assertForbidden();
        $this->withToken($otherFacilityUser)
            ->patchJson('/api/v1/organization/cluster', ['name' => 'تعديل مرفوض'], $this->patchHeaders('"1"'))
            ->assertForbidden();

        $this->assertDatabaseCount('clusters', 0);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_facility_list_uses_principal_bound_opaque_cursor_pagination(): void
    {
        $token = $this->loginToken();
        $clusterId = $this->createCluster($token);
        foreach (range(1, 3) as $number) {
            $this->withToken($token)->postJson('/api/v1/organization/facilities', [
                'cluster_id' => $clusterId,
                'type_code' => 'center',
                'code' => "CENTER-0{$number}",
                'name' => "مركز {$number}",
            ], $this->writeHeaders("facility-page-{$number}"))->assertCreated();
        }

        $first = $this->withToken($token)
            ->getJson('/api/v1/organization/facilities?limit=2', $this->headers())
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertHeader('Link');
        $cursor = $first->json('next_cursor');
        $this->assertIsString($cursor);

        $this->withToken($token)
            ->getJson('/api/v1/organization/facilities?limit=2&cursor='.rawurlencode($cursor), $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('next_cursor', null)
            ->assertHeaderMissing('Link');

        $this->withToken($token)
            ->getJson('/api/v1/organization/facilities?limit=3&cursor='.rawurlencode($cursor), $this->headers())
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-pagination');
    }

    private function createCluster(string $token): string
    {
        return (string) $this->withToken($token)->postJson('/api/v1/organization/cluster', [
            'code' => 'THC3',
            'name' => 'التجمع الصحي الثالث',
        ], $this->writeHeaders('cluster-for-facility'))->assertCreated()->json('data.id');
    }

    private function createFacility(string $token, string $clusterId): string
    {
        return (string) $this->withToken($token)->postJson('/api/v1/organization/facilities', [
            'cluster_id' => $clusterId,
            'type_code' => 'hospital',
            'code' => 'HOSPITAL-01',
            'name' => 'مستشفى التجمع',
        ], $this->writeHeaders('facility-for-update'))->assertCreated()->json('data.id');
    }

    private function loginToken(
        string $username = 'fixture-account-a',
        string $password = 'fixture-password-a',
    ): string {
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
        return [
            ...$this->headers(),
            'Idempotency-Key' => $key,
        ];
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
