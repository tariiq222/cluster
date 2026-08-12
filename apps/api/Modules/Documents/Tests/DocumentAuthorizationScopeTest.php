<?php

declare(strict_types=1);

namespace Modules\Documents\Tests;

use Database\Seeders\AuthorizationCatalogSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Documents\Application\DocumentAccessRequest;
use Modules\Documents\Application\DocumentAuthorizationRecordFactsBuilder;
use Modules\Documents\Application\DocumentDownloadGrant;
use Modules\Documents\Application\DocumentDownloadService;
use Modules\Documents\Application\DocumentLinkService;
use Modules\Documents\Contracts\DocumentDownloadGrantIssuer;
use Modules\Documents\Contracts\DocumentSourceReference;
use Modules\Documents\Contracts\LinkedResourceAuthorizationFacts;
use Modules\Documents\Contracts\SensitiveAccessEventRecorder;
use Modules\Documents\Features\DocumentLifecycle\Http\GetDocumentController;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Tests\TestCase;

final class DocumentAuthorizationScopeTest extends TestCase
{
    use RefreshDatabase;

    private const CLUSTER_A = '018f6f7d-0000-7000-8000-000000000301';

    private const CLUSTER_B = '018f6f7d-0000-7000-8000-000000000302';

    public const FACILITY_A1 = '018f6f7d-0000-7000-8000-000000000311';

    private const FACILITY_A2 = '018f6f7d-0000-7000-8000-000000000312';

    private const FACILITY_B1 = '018f6f7d-0000-7000-8000-000000000313';

    private const UNIT_X = '018f6f7d-0000-7000-8000-000000000321';

    private const UNIT_Y = '018f6f7d-0000-7000-8000-000000000322';

    private const DOCUMENT_ID = '018f6f7d-0000-7000-8000-000000000331';

    private const DOCUMENT_PUBLIC_ID = '018f6f7d-0000-7000-8000-000000000332';

    private const FACILITY_DOCUMENT_ID = '018f6f7d-0000-7000-8000-000000000333';

    private const FACILITY_DOCUMENT_PUBLIC_ID = '018f6f7d-0000-7000-8000-000000000334';

    private const UNKNOWN_DOCUMENT_ID = '018f6f7d-0000-7000-8000-000000000335';

    private const UNKNOWN_DOCUMENT_PUBLIC_ID = '018f6f7d-0000-7000-8000-000000000336';

    private const UNKNOWN_OWNER_ID = '018f6f7d-0000-7000-8000-000000000399';

    private const TASK_ID = '018f6f7d-0000-7000-8000-000000000391';

    private const VERSION_ID = '018f6f7d-0000-7000-8000-000000000392';

    private const VERSION_PUBLIC_ID = '018f6f7d-0000-7000-8000-000000000393';

    private const STORAGE_OBJECT_ID = '018f6f7d-0000-7000-8000-000000000394';

    public const USER_ID = '018f6f7d-0000-7000-8000-000000000341';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindRealAccessDecision();

        $this->seed(AuthorizationCatalogSeeder::class);
        $this->seedOrganizationTree();
        $this->insertDocument(self::DOCUMENT_ID, self::DOCUMENT_PUBLIC_ID, self::UNIT_X);
        $this->insertDocument(self::FACILITY_DOCUMENT_ID, self::FACILITY_DOCUMENT_PUBLIC_ID, self::FACILITY_A1);
        $this->insertDocument(self::UNKNOWN_DOCUMENT_ID, self::UNKNOWN_DOCUMENT_PUBLIC_ID, self::UNKNOWN_OWNER_ID);
    }

    public function test_unit_owned_document_uses_authoritative_ancestry_for_read_scope_decisions(): void
    {
        $facts = $this->readFactsFor(self::DOCUMENT_PUBLIC_ID);

        $this->assertSame(self::FACILITY_A1, $facts->ownerFacilityId);
        $this->assertSame(self::UNIT_X, $facts->organizationUnitId);
        $this->assertSame(self::CLUSTER_A, $facts->clusterId);
        $this->assertSame(self::DOCUMENT_ID, $facts->recordId);
        $this->assertSame(self::USER_ID, $facts->createdByUserId);
        $this->assertSame('document', $facts->resourceType);
        $this->assertSame('internal', $facts->classification);
        $this->assertSame('active', $facts->lifecycleState);
        $this->assertFalse($facts->legalHold);
        $this->assertSame(1, $facts->lockVersion);

        $scopeCases = [
            ['cluster', self::CLUSTER_A, true],
            ['cluster', self::CLUSTER_B, false],
            ['facility', self::FACILITY_A1, true],
            ['facility', self::FACILITY_A2, false],
            ['unit', self::UNIT_X, true],
            ['unit', self::UNIT_Y, false],
        ];

        foreach ($scopeCases as [$scopeType, $scopeId, $expected]) {
            $this->assignReaderScope($scopeType, $scopeId);
            $decision = $this->decider()->evaluateOnly(['user_id' => self::USER_ID], 'documents.read', $facts);

            $this->assertSame($expected, $decision->isAllowed(), "Unexpected decision for {$scopeType}:{$scopeId}.");
        }
    }

    public function test_facility_owned_document_resolves_facility_and_cluster_without_unit(): void
    {
        $facts = $this->readFactsFor(self::FACILITY_DOCUMENT_PUBLIC_ID);

        $this->assertSame(self::FACILITY_A1, $facts->ownerFacilityId);
        $this->assertSame(self::CLUSTER_A, $facts->clusterId);
        $this->assertNull($facts->organizationUnitId);
    }

    public function test_unknown_document_owner_fails_closed_for_rbac_cluster_facility_and_unit_scopes(): void
    {
        $facts = $this->readFactsFor(self::UNKNOWN_DOCUMENT_PUBLIC_ID);

        $this->assertNull($facts->ownerFacilityId);
        $this->assertNull($facts->organizationUnitId);
        $this->assertNull($facts->clusterId);

        foreach ([
            ['cluster', self::CLUSTER_A],
            ['facility', self::FACILITY_A1],
            ['unit', self::UNIT_X],
        ] as [$scopeType, $scopeId]) {
            $this->assignReaderScope($scopeType, $scopeId);
            $decision = $this->decider()->evaluateOnly(['user_id' => self::USER_ID], 'documents.read', $facts);

            $this->assertFalse($decision->isAllowed(), "Unknown owner must deny {$scopeType}:{$scopeId}.");
        }
    }

    public function test_document_access_controller_declares_builder_dependency(): void
    {
        $constructor = (new \ReflectionClass(GetDocumentController::class))->getConstructor();
        $this->assertNotNull($constructor);
        $builderParameter = $constructor->getParameters()[2] ?? null;
        $this->assertNotNull($builderParameter);
        $type = $builderParameter->getType();
        if (! $type instanceof \ReflectionNamedType) {
            $this->fail('The document facts builder dependency must use a named type.');
        }
        $this->assertSame(DocumentAuthorizationRecordFactsBuilder::class, $type->getName());
    }

    public function test_link_service_passes_authoritative_document_facts_to_decide_access(): void
    {
        $this->insertAvailableVersion(self::DOCUMENT_ID);
        $document = DB::table('documents')->where('public_id', self::DOCUMENT_PUBLIC_ID)->first();
        $this->assertIsString($document->owner_organization_unit_id);
        $access = new RecordingDocumentAccess;
        $service = new DocumentLinkService(
            $access,
            new DocumentScopeLinkedFacts,
            $this->app->make(DocumentAuthorizationRecordFactsBuilder::class),
        );

        $service->link(
            self::DOCUMENT_PUBLIC_ID,
            new DocumentSourceReference('work-records', 'work_record', self::TASK_ID),
            'attachment',
            self::USER_ID,
            self::FACILITY_A1,
        );

        $facts = $access->factsByCapability['documents.link'] ?? null;
        $this->assertInstanceOf(RecordFacts::class, $facts);
        $this->assertSame(self::FACILITY_A1, $facts->ownerFacilityId);
        $this->assertSame(self::UNIT_X, $facts->organizationUnitId);
        $this->assertSame(self::CLUSTER_A, $facts->clusterId);
        $this->assertSame(self::USER_ID, $facts->createdByUserId);
    }

    public function test_download_service_passes_authoritative_document_facts_to_decide_access(): void
    {
        $this->insertAvailableVersion(self::DOCUMENT_ID);
        $access = new RecordingDocumentAccess;
        $service = new DocumentDownloadService(
            $access,
            new DocumentScopeLinkedFacts,
            $this->app->make(DocumentAuthorizationRecordFactsBuilder::class),
            new DocumentScopeGrantIssuer,
            new DocumentScopeSensitiveAccessRecorder,
        );

        $service->download(
            self::DOCUMENT_PUBLIC_ID,
            self::VERSION_PUBLIC_ID,
            new DocumentAccessRequest(self::USER_ID, self::FACILITY_A1, '018f6f7d-0000-7000-8000-000000000395'),
        );

        $facts = $access->factsByCapability['documents.download'] ?? null;
        $this->assertInstanceOf(RecordFacts::class, $facts);
        $this->assertSame(self::FACILITY_A1, $facts->ownerFacilityId);
        $this->assertSame(self::UNIT_X, $facts->organizationUnitId);
        $this->assertSame(self::CLUSTER_A, $facts->clusterId);
        $this->assertSame(self::USER_ID, $facts->createdByUserId);
    }

    public function test_link_service_keeps_unknown_owner_unscoped_instead_of_using_actor_facility(): void
    {
        $this->insertAvailableVersion(self::UNKNOWN_DOCUMENT_ID);
        $access = new RecordingDocumentAccess;
        $service = new DocumentLinkService(
            $access,
            new DocumentScopeLinkedFacts,
            $this->app->make(DocumentAuthorizationRecordFactsBuilder::class),
        );

        $service->link(
            self::UNKNOWN_DOCUMENT_PUBLIC_ID,
            new DocumentSourceReference('work-records', 'work_record', self::TASK_ID),
            'attachment',
            self::USER_ID,
            self::FACILITY_A1,
        );

        $facts = $access->factsByCapability['documents.link'] ?? null;
        $this->assertInstanceOf(RecordFacts::class, $facts);
        $this->assertNull($facts->ownerFacilityId);
        $this->assertNull($facts->organizationUnitId);
        $this->assertNull($facts->clusterId);
    }

    public function test_download_service_keeps_unknown_owner_unscoped_instead_of_using_actor_facility(): void
    {
        $this->insertAvailableVersion(self::UNKNOWN_DOCUMENT_ID);
        $access = new RecordingDocumentAccess;
        $service = new DocumentDownloadService(
            $access,
            new DocumentScopeLinkedFacts,
            $this->app->make(DocumentAuthorizationRecordFactsBuilder::class),
            new DocumentScopeGrantIssuer,
            new DocumentScopeSensitiveAccessRecorder,
        );

        $service->download(
            self::UNKNOWN_DOCUMENT_PUBLIC_ID,
            self::VERSION_PUBLIC_ID,
            new DocumentAccessRequest(self::USER_ID, self::FACILITY_A1, '018f6f7d-0000-7000-8000-000000000396'),
        );

        $facts = $access->factsByCapability['documents.download'] ?? null;
        $this->assertInstanceOf(RecordFacts::class, $facts);
        $this->assertNull($facts->ownerFacilityId);
        $this->assertNull($facts->organizationUnitId);
        $this->assertNull($facts->clusterId);
    }

    private function readFactsFor(string $publicId): RecordFacts
    {
        $access = new RecordingDocumentAccess;
        $controller = new GetDocumentController(
            new DocumentScopePrincipal,
            $access,
            $this->app->make(DocumentAuthorizationRecordFactsBuilder::class),
        );
        $response = $controller(HttpRequest::create('/documents/'.$publicId, 'GET', server: [
            'HTTP_X_CORRELATION_ID' => '018f6f7d-0000-7000-8000-000000000399',
        ]), $publicId);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertInstanceOf(RecordFacts::class, $access->readFacts);

        return $access->readFacts;
    }

    private function decider(): DecideAccess
    {
        return $this->app->make(DecideAccess::class);
    }

    private function assignReaderScope(string $scopeType, string $scopeId): void
    {
        $roleId = '018f6f7d-0000-7000-8000-000000000351';
        DB::table('roles')->updateOrInsert(['id' => $roleId], [
            'code' => 'document-scope-reader',
            'name_ar' => 'قارئ نطاق المستندات',
            'name_en' => 'Document scope reader',
            'role_type' => 'administrative',
            'status' => 'active',
            'is_system_role' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $capabilityId = DB::table('capabilities')->where('capability_code', 'documents.read')->value('id');
        $this->assertIsString($capabilityId);
        DB::table('role_capabilities')->updateOrInsert(
            ['role_id' => $roleId, 'capability_id' => $capabilityId],
            ['effect' => 'allow', 'created_at' => now(), 'updated_at' => now()],
        );
        DB::table('role_assignments')->where('user_id', self::USER_ID)->delete();
        DB::table('role_assignments')->insert([
            'id' => '018f6f7d-0000-7000-8000-000000000352',
            'user_id' => self::USER_ID,
            'role_id' => $roleId,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'start_at' => now()->subMinute(),
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => self::USER_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedOrganizationTree(): void
    {
        $now = now();
        DB::table('facility_types')->insert([
            'id' => '018f6f7d-0000-7000-8000-000000000361',
            'code' => 'document-hospital',
            'name_ar' => 'مستشفى المستندات',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('unit_types')->insert([
            'id' => '018f6f7d-0000-7000-8000-000000000362',
            'code' => 'document-department',
            'name_ar' => 'إدارة المستندات',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ([self::CLUSTER_A, self::CLUSTER_B] as $index => $clusterId) {
            DB::table('clusters')->insert([
                'id' => $clusterId,
                'singleton_key' => $index + 1,
                'code' => 'DOCUMENT-CLUSTER-'.($index + 1),
                'name_ar' => 'تجمع المستندات '.($index + 1),
                'name_en' => 'Document cluster '.($index + 1),
                'status' => 'active',
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        foreach ([
            [self::FACILITY_A1, self::CLUSTER_A, 'A1'],
            [self::FACILITY_A2, self::CLUSTER_A, 'A2'],
            [self::FACILITY_B1, self::CLUSTER_B, 'B1'],
        ] as [$facilityId, $clusterId, $code]) {
            DB::table('facilities')->insert([
                'id' => $facilityId,
                'cluster_id' => $clusterId,
                'facility_type_id' => '018f6f7d-0000-7000-8000-000000000361',
                'code' => 'DOCUMENT-FACILITY-'.$code,
                'name_ar' => 'منشأة المستندات '.$code,
                'name_en' => 'Document facility '.$code,
                'status' => 'active',
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        foreach ([
            [self::UNIT_X, self::CLUSTER_A, self::FACILITY_A1, 'X'],
            [self::UNIT_Y, self::CLUSTER_A, self::FACILITY_A2, 'Y'],
        ] as [$unitId, $clusterId, $facilityId, $code]) {
            DB::table('organization_units')->insert([
                'id' => $unitId,
                'cluster_id' => $clusterId,
                'parent_id' => $facilityId,
                'parent_type' => 'facility',
                'unit_type_id' => '018f6f7d-0000-7000-8000-000000000362',
                'code' => 'DOCUMENT-UNIT-'.$code,
                'name_ar' => 'وحدة المستندات '.$code,
                'name_en' => 'Document unit '.$code,
                'status' => 'active',
                'path_cache' => '/'.$clusterId.'/'.$facilityId.'/'.$unitId,
                'depth' => 2,
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function insertDocument(string $id, string $publicId, string $ownerId): void
    {
        DB::table('documents')->insert([
            'id' => $id,
            'public_id' => $publicId,
            'owner_organization_unit_id' => $ownerId,
            'created_by_user_id' => self::USER_ID,
            'name' => 'Scoped document',
            'description' => null,
            'classification' => 'internal',
            'status' => 'active',
            'current_version_id' => null,
            'retention_until' => null,
            'retention_policy_key' => null,
            'restriction_policy_key' => 'document-restriction-v1',
            'legal_hold' => false,
            'legal_hold_reason' => null,
            'legal_hold_at' => null,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertAvailableVersion(string $documentId): void
    {
        $now = now();
        DB::table('document_storage_objects')->insert([
            'id' => self::STORAGE_OBJECT_ID,
            'disk' => 'documents-available',
            'object_key' => 'document-scope/'.self::VERSION_PUBLIC_ID,
            'storage_class' => 'standard',
            'immutable' => true,
            'immutable_since' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('document_versions')->insert([
            'id' => self::VERSION_ID,
            'public_id' => self::VERSION_PUBLIC_ID,
            'document_id' => $documentId,
            'storage_object_id' => self::STORAGE_OBJECT_ID,
            'version_number' => 1,
            'original_filename' => 'document.pdf',
            'declared_mime_type' => 'application/pdf',
            'detected_mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'sha256' => str_repeat('a', 64),
            'scan_status' => 'clean',
            'availability_status' => 'available',
            'scan_engine_version' => 'test',
            'scan_result' => null,
            'scanned_at' => $now,
            'available_at' => $now,
            'created_by_user_id' => self::USER_ID,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}

final class DocumentScopePrincipal implements ResolveDevelopmentFixturePrincipal
{
    public function issue(array $principal): array
    {
        return ['access_token' => 'document-scope-test', 'expires_at' => '2026-08-12T12:00:00Z'];
    }

    public function resolve(HttpRequest $request): array
    {
        return ['user_id' => DocumentAuthorizationScopeTest::USER_ID, 'facility_id' => DocumentAuthorizationScopeTest::FACILITY_A1];
    }
}

final class RecordingDocumentAccess implements DecideAccess
{
    public ?RecordFacts $readFacts = null;

    /** @var array<string, RecordFacts|null> */
    public array $factsByCapability = [];

    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        $this->factsByCapability[$capability] = $facts;
        if ($capability === 'documents.read') {
            $this->readFacts = $facts;
        }

        $resourceType = $facts === null ? 'document' : $facts->resourceType;
        $classification = $facts === null ? 'confidential' : $facts->classification;

        return new AccessDecision('allow', $capability, $resourceType, [], 'test-policy-v1', 'test-facts-v1', $classification);
    }

    public function evaluateOnly(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        return $this->decide($actor, $capability, $facts);
    }
}

final class DocumentScopeLinkedFacts implements LinkedResourceAuthorizationFacts
{
    public function resolve(DocumentSourceReference $reference): RecordFacts
    {
        return new RecordFacts(null, $reference->sourceType, 'internal');
    }
}

final class DocumentScopeGrantIssuer implements DocumentDownloadGrantIssuer
{
    public function issue(string $documentId, string $versionId, string $principalId): DocumentDownloadGrant
    {
        return new DocumentDownloadGrant($documentId, $versionId, 'https://download.invalid/'.$versionId, new DateTimeImmutable('+5 minutes'), 'document-scope-test');
    }
}

final class DocumentScopeSensitiveAccessRecorder implements SensitiveAccessEventRecorder
{
    public function recordDownload(string $documentId, string $versionId, string $classification, DocumentAccessRequest $request, AccessDecision $decision): void {}
}
