<?php

declare(strict_types=1);

namespace Modules\Tasks\Tests;

use Database\Seeders\DevelopmentJourneyAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\CapabilityCatalog;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Documents\Contracts\LinkDocument;
use Tests\TestCase;

/**
 * POST /tasks/{taskId}/documents against the REAL Documents link path:
 * the container's LinkDocument binding (DocumentLinkService) plus the
 * RegisteredLinkedResourceAuthorizationFacts router resolving the
 * Tasks-owned facts provider from a real documents/documents_versions
 * row. The link row, idempotency record, and notification must all land.
 */
final class TaskDocumentsRealLinkIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION = '018f6f7d-0c00-7000-8000-000000000604';

    private const USER_A = '018f6f7d-0c00-7000-8000-000000000021';

    private const USER_B = '018f6f7d-0c00-7000-8000-000000000022';

    private const FACILITY_A = '018f6f7d-0c00-7000-8000-000000000011';

    public const DOCUMENT_PUBLIC_ID = '018f6f7d-0c00-7000-8000-000000000099';

    private const DOCUMENT_ID = '018f6f7d-0c00-7000-8000-000000000098';

    private const PRINCIPAL_ID = '018f6f7d-0c00-7000-8000-000000000097';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DevelopmentJourneyAuthorizationSeeder::class);
        $this->token = (string) $this->postJson('/api/v1/auth/login', [
            'username' => 'fixture-account-a',
            'password' => 'fixture-password-a',
        ], ['X-Correlation-ID' => self::CORRELATION])->assertOk()->json('data.access_token');

        $this->app->instance(DecideAccess::class, new class implements DecideAccess
        {
            public function evaluateOnly(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
            {
                return $this->decide($actor, $capability, $facts);
            }

            public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
            {
                $isUnsupported = ! CapabilityCatalog::supports($capability);

                return new AccessDecision(
                    decision: $isUnsupported ? 'deny' : 'allow',
                    action: $capability,
                    resourceType: $facts === null ? 'task' : $facts->resourceType,
                    reasonCodes: [$isUnsupported ? 'capability_not_supported' : 'test_allow'],
                    policyVersion: 'test-fixture-v1',
                    factsVersion: $facts === null ? 'v1' : $facts->factsVersion,
                    classification: $facts === null ? 'internal' : $facts->classification,
                );
            }
        });
        // Deliberately NOT stubbed: the real container binding
        // LinkDocument → DocumentLinkService must drive the link.
        $this->assertInstanceOf(LinkDocument::class, $this->app->make(LinkDocument::class));
    }

    public function test_attach_runs_the_real_link_service_and_router_and_persists_a_document_link(): void
    {
        $taskId = $this->seedTask(self::USER_B);
        $this->seedDocumentWithAvailableVersion();

        $resp = $this->withToken($this->token)->postJson('/api/v1/tasks/'.$taskId.'/documents', [
            'document_id' => self::DOCUMENT_PUBLIC_ID,
        ], [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => 'idem-real-link-'.Str::uuid7()->toString(),
        ]);
        $resp->assertStatus(201);

        $linkId = $resp->json('data.id');
        $this->assertMatchesRegularExpression('/\A[0-9a-f-]{36}\z/', (string) $linkId);

        $link = DB::table('document_links')->where('id', $linkId)->first();
        $this->assertNotNull($link);
        $this->assertSame(self::DOCUMENT_ID, (string) $link->document_id);
        $this->assertSame('tasks', (string) $link->source_module);
        $this->assertSame('task', (string) $link->source_type);
        $this->assertSame($taskId, (string) $link->source_id);
        $this->assertSame('attachment', (string) $link->relation_type);
        $this->assertSame('active', (string) $link->status);
        $this->assertSame('internal', (string) $link->link_classification);
        $this->assertSame(self::USER_A, (string) $link->linked_by_user_id);

        $recipients = DB::table('notifications')->where('type', 'task.document_attached')->pluck('recipient_user_id')->all();
        $this->assertSame([self::USER_B], $recipients);
    }

    public function test_attach_without_an_available_version_returns_404_through_the_real_service(): void
    {
        $taskId = $this->seedTask(self::USER_A);
        $this->seedDocument(['status' => 'draft']);

        $resp = $this->withToken($this->token)->postJson('/api/v1/tasks/'.$taskId.'/documents', [
            'document_id' => self::DOCUMENT_PUBLIC_ID,
        ], [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => 'idem-real-link-'.Str::uuid7()->toString(),
        ]);
        $resp->assertNotFound();
        $this->assertSame('https://cluster.example/problems/resource-not-found', $resp->json('type'));
        $this->assertSame(0, DB::table('document_links')->count());
    }

    private function seedTask(string $assignee, string $creator = self::USER_A): string
    {
        $id = (string) Str::uuid7();
        DB::table('tasks')->insert([
            'id' => $id,
            'title' => 'Doc task',
            'description' => null,
            'created_by_user_id' => $creator,
            'assignee_user_id' => $assignee,
            'owner_organization_unit_id' => self::FACILITY_A,
            'status' => 'open',
            'priority' => 'normal',
            'classification' => 'internal',
            'completion_policy' => 'direct',
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedDocument(array $overrides = []): void
    {
        $now = now();
        DB::table('documents')->insertOrIgnore([
            ...[
                'id' => self::DOCUMENT_ID,
                'public_id' => self::DOCUMENT_PUBLIC_ID,
                'owner_organization_unit_id' => self::FACILITY_A,
                'created_by_user_id' => self::PRINCIPAL_ID,
                'name' => 'Linkable document',
                'description' => null,
                'classification' => 'internal',
                'status' => 'active',
                'current_version_id' => null,
                'retention_until' => null,
                'retention_policy_key' => null,
                'restriction_policy_key' => 'documents.default',
                'legal_hold' => false,
                'legal_hold_reason' => null,
                'legal_hold_at' => null,
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ...$overrides,
        ]);
    }

    private function seedDocumentWithAvailableVersion(): void
    {
        $this->seedDocument();
        $now = now();
        $storageObjectId = (string) Str::uuid7();
        $versionId = (string) Str::uuid7();
        DB::table('document_storage_objects')->insert([
            'id' => $storageObjectId,
            'disk' => 'documents-private',
            'object_key' => $storageObjectId.'.blob',
            'etag' => 'etag-real-link',
            'generation' => 'generation-real-link',
            'storage_class' => 'private',
            'immutable' => true,
            'immutable_since' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('document_versions')->insert([
            'id' => $versionId,
            'public_id' => (string) Str::uuid7(),
            'document_id' => self::DOCUMENT_ID,
            'storage_object_id' => $storageObjectId,
            'version_number' => 1,
            'original_filename' => 'linkable.pdf',
            'declared_mime_type' => 'application/pdf',
            'detected_mime_type' => 'application/pdf',
            'size_bytes' => 512,
            'sha256' => hash('sha256', 'linkable'),
            'scan_status' => 'clean',
            'availability_status' => 'available',
            'scan_engine_version' => 'test',
            'scan_result' => null,
            'scanned_at' => $now,
            'available_at' => $now,
            'promotion_requested_at' => null,
            'created_by_user_id' => self::PRINCIPAL_ID,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('documents')->where('id', self::DOCUMENT_ID)->update(['current_version_id' => $versionId]);
    }
}
