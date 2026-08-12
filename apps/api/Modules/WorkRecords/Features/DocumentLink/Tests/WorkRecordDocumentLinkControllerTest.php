<?php

namespace Modules\WorkRecords\Features\DocumentLink\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\WorkRecords\Features\DocumentLink\Http\WorkRecordDocumentLinkController;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class WorkRecordDocumentLinkControllerTest extends TestCase
{
    use RefreshDatabase;

    private const RECORD_ID = '019a0000-0000-7000-8000-000000000201';

    private const DOCUMENT_PUBLIC_ID = '019a0000-0000-7000-8000-000000000202';

    private const DOCUMENT_ID = '019a0000-0000-7000-8000-000000000203';

    private const VERSION_ID = '019a0000-0000-7000-8000-000000000204';

    private const STORAGE_ID = '019a0000-0000-7000-8000-000000000205';

    private const FACILITY_ID = '019a0000-0000-7000-8000-000000000206';

    private const CLUSTER_ID = '019a0000-0000-7000-8000-00000000020a';

    private const FACILITY_TYPE_ID = '019a0000-0000-7000-8000-00000000020b';

    private const USER_ID = '019a0000-0000-7000-8000-000000000207';

    private const CORRELATION_ID = '019a0000-0000-7000-8000-000000000208';

    protected function setUp(): void
    {
        parent::setUp();
        $now = now();
        DB::table('clusters')->insert([
            'id' => self::CLUSTER_ID,
            'singleton_key' => 1,
            'code' => 'WR-DOCUMENT-LINK-CLUSTER',
            'name_ar' => 'تجمع ربط السجلات',
            'name_en' => 'Work record document-link cluster',
            'status' => 'active',
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('facility_types')->insert([
            'id' => self::FACILITY_TYPE_ID,
            'code' => 'work_record_document_link_facility',
            'name_ar' => 'منشأة ربط السجلات',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('facilities')->insert([
            'id' => self::FACILITY_ID,
            'cluster_id' => self::CLUSTER_ID,
            'facility_type_id' => self::FACILITY_TYPE_ID,
            'code' => 'WR-DOCUMENT-LINK-FACILITY',
            'name_ar' => 'منشأة ربط السجلات',
            'name_en' => 'Work record document-link facility',
            'status' => 'active',
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->seedDocument();
        $this->app->instance(ResolveDevelopmentFixturePrincipal::class, $this->principals());
        $this->app->instance(DecideAccess::class, $this->allowingAccess());
    }

    public function test_linking_to_a_nonexistent_record_returns_404_and_writes_no_state(): void
    {
        $response = $this->controller()($this->request(), self::RECORD_ID);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('https://cluster.example/problems/resource-not-found', $response->getData(true)['type']);
        $this->assertDatabaseCount('document_links', 0);
        $this->assertDatabaseCount('work_record_idempotency_keys', 0);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    #[DataProvider('unlinkableStatusProvider')]
    public function test_linking_to_an_unlinkable_record_returns_409_and_writes_no_state(string $status): void
    {
        $this->seedRecord($status);

        $response = $this->controller()($this->request(), self::RECORD_ID);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('https://cluster.example/problems/invalid-record-transition', $response->getData(true)['type']);
        $this->assertDatabaseCount('document_links', 0);
        $this->assertDatabaseCount('work_record_idempotency_keys', 0);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public static function unlinkableStatusProvider(): array
    {
        return [
            'archived records refuse document links' => ['archived'],
            'cancelled records refuse document links' => ['cancelled'],
        ];
    }

    public function test_linking_to_a_linkable_record_persists_the_link(): void
    {
        $this->seedRecord('submitted');

        $response = $this->controller()($this->request(), self::RECORD_ID);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertDatabaseHas('document_links', [
            'source_module' => 'work-records',
            'source_type' => 'work_record',
            'source_id' => self::RECORD_ID,
            'relation_type' => 'attachment',
            'status' => 'active',
        ]);
        $this->assertDatabaseCount('work_record_idempotency_keys', 1);
        $access = $this->app->make(DecideAccess::class);
        $this->assertInstanceOf(RecordingDocumentLinkAccess::class, $access);
        $this->assertNotNull($access->facts);
        $this->assertSame(self::FACILITY_ID, $access->facts->ownerFacilityId);
        $this->assertSame(self::CLUSTER_ID, $access->facts->clusterId);
        $this->assertSame(self::RECORD_ID, $access->facts->recordId);
        $this->assertSame(self::USER_ID, $access->facts->createdByUserId);
        $this->assertSame('submitted', $access->facts->lifecycleState);
        $this->assertSame('request', $access->facts->fieldPolicyKey);
        $this->assertSame(1, $access->facts->lockVersion);
    }

    private function controller(): WorkRecordDocumentLinkController
    {
        return $this->app->make(WorkRecordDocumentLinkController::class);
    }

    private function request(): Request
    {
        return Request::create(
            '/api/v1/work-records/'.self::RECORD_ID.'/documents',
            'POST',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CORRELATION_ID' => self::CORRELATION_ID,
                'HTTP_IDEMPOTENCY_KEY' => 'link-'.self::RECORD_ID,
            ],
            json_encode(['document_id' => self::DOCUMENT_PUBLIC_ID, 'relation_type' => 'attachment'], JSON_THROW_ON_ERROR),
        );
    }

    private function seedDocument(): void
    {
        $now = now();
        DB::table('documents')->insert([
            'id' => self::DOCUMENT_ID,
            'public_id' => self::DOCUMENT_PUBLIC_ID,
            'owner_organization_unit_id' => self::FACILITY_ID,
            'created_by_user_id' => self::USER_ID,
            'name' => 'Linkable document',
            'description' => null,
            'classification' => 'internal',
            'restriction_facts' => null,
            'status' => 'draft',
            'current_version_id' => self::VERSION_ID,
            'retention_until' => null,
            'retention_policy_key' => null,
            'legal_hold' => false,
            'legal_hold_reason' => null,
            'legal_hold_at' => null,
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('document_storage_objects')->insert([
            'id' => self::STORAGE_ID,
            'disk' => 'documents-private',
            'object_key' => 'linkable.blob',
            'etag' => 'etag-linkable',
            'generation' => 'generation-linkable',
            'storage_class' => 'private',
            'immutable' => true,
            'immutable_since' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('document_versions')->insert([
            'id' => self::VERSION_ID,
            'public_id' => '019a0000-0000-7000-8000-000000000209',
            'document_id' => self::DOCUMENT_ID,
            'storage_object_id' => self::STORAGE_ID,
            'version_number' => 1,
            'original_filename' => 'linkable.pdf',
            'declared_mime_type' => 'application/pdf',
            'detected_mime_type' => 'application/pdf',
            'size_bytes' => 100,
            'sha256' => hash('sha256', 'linkable'),
            'scan_status' => 'clean',
            'availability_status' => 'available',
            'scan_engine_version' => 'test',
            'scan_result' => null,
            'scanned_at' => $now,
            'available_at' => $now,
            'promotion_requested_at' => null,
            'created_by_user_id' => self::USER_ID,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedRecord(string $status): void
    {
        DB::table('work_records')->insert([
            'id' => self::RECORD_ID,
            'record_number' => 'WR-DOCLINK-001',
            'work_type_version_id' => '019a0000-0000-7000-8000-000000000210',
            'owner_facility_id' => self::FACILITY_ID,
            'creator_user_id' => self::USER_ID,
            'classification' => 'internal',
            'field_policy_key' => 'request',
            'status' => $status,
            'payload' => json_encode(['title' => 'Document link regression'], JSON_THROW_ON_ERROR),
            'lock_version' => 1,
            'submitted_at' => '2026-07-01 10:20:30',
            'created_at' => '2026-07-01 10:00:00',
            'updated_at' => '2026-07-01 10:20:30',
        ]);
    }

    private function principals(): ResolveDevelopmentFixturePrincipal
    {
        return new class implements ResolveDevelopmentFixturePrincipal
        {
            public function issue(array $principal): array
            {
                return ['access_token' => 'test', 'expires_at' => now()->addHour()->toIso8601String()];
            }

            public function resolve(Request $request): array
            {
                return ['user_id' => '019a0000-0000-7000-8000-000000000207', 'facility_id' => '019a0000-0000-7000-8000-000000000206'];
            }
        };
    }

    private function allowingAccess(): DecideAccess
    {
        return new RecordingDocumentLinkAccess;
    }
}

final class RecordingDocumentLinkAccess implements DecideAccess
{
    public ?RecordFacts $facts = null;

    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        $this->facts = $facts;

        return new AccessDecision('allow', $capability, 'work_record', [], 'test', 'test', 'internal');
    }

    public function evaluateOnly(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        return $this->decide($actor, $capability, $facts);
    }
}
