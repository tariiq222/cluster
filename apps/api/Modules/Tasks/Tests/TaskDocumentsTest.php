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
use Tests\TestCase;

/**
 * POST /tasks/{taskId}/documents behavior:
 *   - authorized document → 201 + notification
 *   - foreign/unavailable document → 404
 *   - principal outside the task's relationship → 404 on show
 *
 * The link itself goes through Modules\Documents\Contracts\LinkDocument;
 * here we replace the in-process binding with a deterministic double so
 * the test exercises the Tasks controller's authorization path without
 * depending on the Documents storage tables.
 */
final class TaskDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION = '018f6f7d-0c00-7000-8000-000000000604';

    private const USER_A = '018f6f7d-0c00-7000-8000-000000000021';

    private const USER_B = '018f6f7d-0c00-7000-8000-000000000022';

    private const FACILITY_A = '018f6f7d-0c00-7000-8000-000000000011';

    public const DOCUMENT_ID = '018f6f7d-0c00-7000-8000-000000000099';

    private const FOREIGN_DOCUMENT_ID = '018f6f7d-0c00-7000-8000-000000000098';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DevelopmentJourneyAuthorizationSeeder::class);
        $this->token = (string) $this->postJson('/api/v1/auth/login', [
            'username' => 'fixture-account-a',
            'password' => 'fixture-password-a',
        ], ['X-Correlation-ID' => self::CORRELATION])->assertOk()->json('data.access_token');

        $this->app->instance(\Modules\Documents\Contracts\LinkDocument::class, new class implements \Modules\Documents\Contracts\LinkDocument
        {
            public function link(
                string $documentId,
                \Modules\Documents\Contracts\DocumentSourceReference $reference,
                string $relationType,
                string $principalId,
                string $facilityId,
                ?string $constraintPolicyKey = null,
            ): string {
                if ($documentId !== TaskDocumentsTest::DOCUMENT_ID) {
                    throw new \DomainException('document_not_available_for_link');
                }

                return (string) Str::uuid7();
            }
        });

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

    public function test_attach_authorized_document_returns_201_and_emits_notification(): void
    {
        // Created by the actor, assigned to B: the assignee is the recipient.
        $taskId = $this->seedTask(self::USER_B);

        $resp = $this->withToken($this->token)->postJson('/api/v1/tasks/'.$taskId.'/documents', [
            'document_id' => self::DOCUMENT_ID,
        ], [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => 'idem-doc-'.Str::uuid7()->toString(),
        ]);
        $resp->assertStatus(201);

        $recipients = DB::table('notifications')->where('type', 'task.document_attached')->pluck('recipient_user_id')->all();
        $this->assertSame([self::USER_B], $recipients);
    }

    public function test_attach_unavailable_document_returns_404(): void
    {
        $taskId = $this->seedTask(self::USER_A);

        $resp = $this->withToken($this->token)->postJson('/api/v1/tasks/'.$taskId.'/documents', [
            'document_id' => self::FOREIGN_DOCUMENT_ID,
        ], [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => 'idem-doc-'.Str::uuid7()->toString(),
        ]);
        $resp->assertNotFound();
    }

    public function test_attach_to_unrelated_task_returns_404(): void
    {
        // B-owned task; caller A has no relationship.
        $taskId = $this->seedTask(self::USER_B, self::USER_B);

        $resp = $this->withToken($this->token)->postJson('/api/v1/tasks/'.$taskId.'/documents', [
            'document_id' => self::DOCUMENT_ID,
        ], [
            'X-Correlation-ID' => self::CORRELATION,
            'Idempotency-Key' => 'idem-doc-'.Str::uuid7()->toString(),
        ]);
        $resp->assertNotFound();
    }
}
