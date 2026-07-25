<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Documents\Application\StoredObjectProperties;
use Modules\Documents\Contracts\WorkerPrincipalResolver;
use Modules\Documents\Domain\DocumentRetentionPolicy;
use Modules\Documents\Domain\DocumentUploadPolicy;
use Modules\Documents\Features\DocumentVersion\Http\AddDocumentVersionController;
use Modules\Documents\Features\Upload\Http\CompleteDocumentUploadController;
use Modules\Documents\Features\Upload\DocumentUploadHandler;
use Modules\Documents\Features\Upload\Http\InitiateDocumentUploadController;
use Modules\Documents\Infrastructure\Persistence\DatabaseDocumentAuthorizationFactsReader;
use Modules\Documents\Tests\Support\InMemoryMalwareScanner;
use Modules\Documents\Tests\Support\InMemoryPrivateObjectStorage;
use Tests\TestCase;

/**
 * Optimistic concurrency regression slice — Tasks + Documents.
 *
 * Asserts the contract between the controller boundary and the handler that
 * - complete action passes the expected If-Match version into CompleteTaskHandler;
 * - StaleTaskVersion surfaced from the handler maps to 412 precondition-failed;
 * - CompleteDocumentUploadController parses If-Match and forwards it as
 *   expectedDocumentLockVersion to DocumentUploadHandler;
 * - DocumentUploadHandler::complete raises StaleDocumentLockVersion when the
 *   If-Match version does not match documents.lock_version;
 * - DocumentsApi::domainProblem translates the stale version into 412;
 * - the aggregate lock order (documents → upload intent → idempotency) is
 *   observed inside the transactions of both initiate() and complete().
 *
 * The propagation cases go through the real HTTP route stack, the real
 * controller, the real handler, and the real DB facade. The lock-order
 * cases listen on the connection so we can assert the sequence of
 * lockForUpdate acquisition against the documents, document_upload_intents,
 * and document_idempotency_keys tables.
 *
 * Sequential HTTP calls are explicitly labelled as propagation / regression
 * tests, not concurrent-writer tests. Asserting a final-state invariant
 * after a sequential request is the cheapest faithful exercise of the
 * propagation contract; a true concurrent-writer test lives in
 * TemporaryAssignmentMySqlConcurrencyTest and depends on a real MySQL
 * integration lane plus pcntl workers, which is out of scope here.
 */
final class OptimisticConcurrencyRegressionTest extends TestCase
{
    use RefreshDatabase;
    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-00000000f101';

    public const PRINCIPAL_ID = '018f6f7d-0c00-7000-8000-000000000802';

    public const FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000801';

    private string $token;

    private string $userId;

    private string $facilityId;

    private InMemoryPrivateObjectStorage $storage;

    private InMemoryMalwareScanner $scanner;

    private InitiateDocumentUploadController $initiate;
    private AddDocumentVersionController $addVersion;

    private CompleteDocumentUploadController $complete;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = (string) $this->postJson('/api/v1/auth/login', [
            'username' => 'fixture-account-a',
            'password' => 'fixture-password-a',
        ], ['X-Correlation-ID' => self::CORRELATION_ID])->assertOk()->json('data.access_token');
        $fixtureAccount = DB::table('identity_development_fixture_accounts')->where('username', 'fixture-account-a')->first();
        $this->userId = (string) $fixtureAccount->id;
        $this->facilityId = (string) $fixtureAccount->facility_id;
        $this->storage = new InMemoryPrivateObjectStorage;
        $this->scanner = new InMemoryMalwareScanner;
        $handler = new DocumentUploadHandler(
            $this->storage,
            $this->scanner,
            DocumentUploadPolicy::fromConfig(config('documents')),
            DocumentRetentionPolicy::fromConfig(config('documents')),
        );
        $principals = $this->documentPrincipals();
        $access = $this->documentAccess();
        $authorizationFacts = new DatabaseDocumentAuthorizationFactsReader;
        $this->initiate = new InitiateDocumentUploadController($principals, $access, $handler);
        $this->addVersion = new AddDocumentVersionController($principals, $access, $handler);
        $this->complete = new CompleteDocumentUploadController($principals, $access, $authorizationFacts, $handler);
    }

    public function test_complete_task_endpoint_propagates_a_stale_if_match_to_412_through_the_real_handler(): void
    {
        $taskId = $this->seedTask($this->userId, 'open', 2);

        $stale = $this->withToken($this->token)->postJson('/api/v1/tasks/'.$taskId.'/complete', [], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'Idempotency-Key' => 'stale-complete-'.Str::uuid7()->toString(),
            'If-Match' => '"1"',
        ]);
        $stale->assertStatus(412)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://cluster.example/problems/precondition-failed');

        $this->assertSame('open', DB::table('tasks')->where('id', $taskId)->value('status'));
        $this->assertSame(2, (int) DB::table('tasks')->where('id', $taskId)->value('lock_version'));
        $this->assertSame(0, DB::table('outbox_events')->where('event_type', 'task.completed.v1')->count());
    }

    public function test_complete_task_endpoint_completes_when_if_match_matches_lock_version(): void
    {
        $taskId = $this->seedTask($this->userId, 'open', 1);

        $complete = $this->withToken($this->token)->postJson('/api/v1/tasks/'.$taskId.'/complete', [], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'Idempotency-Key' => 'happy-complete-'.Str::uuid7()->toString(),
            'If-Match' => '"1"',
        ]);
        $complete->assertOk()
            ->assertHeader('ETag', '"2"')
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame('completed', DB::table('tasks')->where('id', $taskId)->value('status'));
        $this->assertSame(2, (int) DB::table('tasks')->where('id', $taskId)->value('lock_version'));
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'task.completed.v1')->count());
    }

    public function test_complete_document_upload_endpoint_propagates_a_stale_if_match_to_412_through_the_real_handler(): void
    {
        $initiated = ($this->initiate)($this->documentRequest('POST', $this->initiatePayload('stale-doc'), 'stale-doc-initiate'))->getData(true);
        $this->storage->completeUpload($initiated['upload_id'], $this->properties('stale-doc.pdf'));
        $documentId = (string) DB::table('documents')->value('public_id');
        DB::table('documents')->where('public_id', $documentId)->update(['lock_version' => 2]);

        $stale = ($this->complete)($this->documentRequest('POST', [
            'sha256' => $this->hashFor('stale-doc.pdf', 512),
            'byte_size' => 512,
        ], 'stale-doc-complete', ['If-Match' => '"1"']), $initiated['upload_id']);

        $this->assertSame(Response::HTTP_PRECONDITION_FAILED, $stale->getStatusCode());
        $this->assertSame('https://cluster.example/problems/precondition-failed', $stale->getData(true)['type']);
        $this->assertNull(DB::table('document_upload_intents')->where('id', $initiated['upload_id'])->value('completed_at'));
        $this->assertSame(2, (int) DB::table('documents')->where('public_id', $documentId)->value('lock_version'));
    }

    public function test_complete_document_upload_endpoint_succeeds_when_if_match_matches_lock_version(): void
    {
        $initiated = ($this->initiate)($this->documentRequest('POST', $this->initiatePayload('happy-doc'), 'happy-doc-initiate'))->getData(true);
        $this->storage->completeUpload($initiated['upload_id'], $this->properties('happy-doc.pdf'));
        $documentId = (string) DB::table('documents')->value('public_id');
        $this->assertSame(1, (int) DB::table('documents')->where('public_id', $documentId)->value('lock_version'));

        $completed = ($this->complete)($this->documentRequest('POST', [
            'sha256' => $this->hashFor('happy-doc.pdf', 512),
            'byte_size' => 512,
        ], 'happy-doc-complete', ['If-Match' => '"1"']), $initiated['upload_id']);

        $this->assertSame(Response::HTTP_ACCEPTED, $completed->getStatusCode());
        $this->assertTrue($completed->getData(true)['accepted']);
        $this->assertSame(1, (int) DB::table('documents')->where('public_id', $documentId)->value('lock_version'));
    }

    public function test_complete_document_upload_without_if_match_preserves_the_existing_precondition_contract(): void
    {
        $initiated = ($this->initiate)($this->documentRequest('POST', $this->initiatePayload('no-ifmatch'), 'no-ifmatch-initiate'))->getData(true);
        $this->storage->completeUpload($initiated['upload_id'], $this->properties('no-ifmatch.pdf'));
        $documentId = (string) DB::table('documents')->value('public_id');

        $completed = ($this->complete)($this->documentRequest('POST', [
            'sha256' => $this->hashFor('no-ifmatch.pdf', 512),
            'byte_size' => 512,
        ], 'no-ifmatch-complete'), $initiated['upload_id']);

        $this->assertSame(Response::HTTP_ACCEPTED, $completed->getStatusCode());
        $this->assertTrue($completed->getData(true)['accepted']);
        $this->assertSame(1, (int) DB::table('documents')->where('public_id', $documentId)->value('lock_version'));
    }

    public function test_document_upload_complete_locks_documents_before_upload_intent_and_idempotency(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('captureLockOrderDuring depends on FOR UPDATE; SQLite has no lockForUpdate emission. Run on MySQL lane.');
        }
        $initiated = ($this->initiate)($this->documentRequest('POST', $this->initiatePayload('lock-order-complete'), 'lock-order-complete-initiate'))->getData(true);
        $this->storage->completeUpload($initiated['upload_id'], $this->properties('lock-order-complete.pdf'));

        $documentId = (string) DB::table('documents')->value('public_id');
        $order = $this->captureLockOrderDuring(
            fn () => ($this->complete)($this->documentRequest('POST', [
                'sha256' => $this->hashFor('lock-order-complete.pdf', 512),
                'byte_size' => 512,
            ], 'lock-order-complete', ['If-Match' => '"1"']), $initiated['upload_id']),
            ['documents', 'document_upload_intents', 'document_idempotency_keys'],
        );

        $documentsIdx = array_search('documents', $order, true);
        $uploadIdx = array_search('document_upload_intents', $order, true);
        $idempotencyIdx = array_search('document_idempotency_keys', $order, true);
        $this->assertNotFalse($documentsIdx, 'The complete handler must lock the documents row.');
        $this->assertNotFalse($uploadIdx, 'The complete handler must lock the upload-intent row.');
        $this->assertNotFalse($idempotencyIdx, 'The complete handler must lock the idempotency row.');
        $this->assertLessThan($uploadIdx, $documentsIdx, 'documents must be locked before document_upload_intents.');
        $this->assertLessThan($idempotencyIdx, $uploadIdx, 'document_upload_intents must be locked before document_idempotency_keys.');
        $this->assertSame($documentId, (string) DB::table('documents')->where('public_id', $documentId)->value('public_id'));
    }

    public function test_document_upload_initiate_locks_documents_before_idempotency_when_targeting_existing_document(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('captureLockOrderDuring depends on FOR UPDATE; SQLite has no lockForUpdate emission. Run on MySQL lane.');
        }
        ($this->initiate)($this->documentRequest('POST', $this->initiatePayload('lock-order-initiate-parent'), 'lock-order-initiate-parent-initiate'));
        $documentId = (string) DB::table('documents')->value('public_id');

        $order = $this->captureLockOrderDuring(
            fn () => ($this->addVersion)($this->documentRequest('POST', $this->initiatePayloadForExistingDocument($documentId, 'lock-order-initiate-version'), 'lock-order-initiate-version-initiate'), $documentId),
            ['documents', 'document_idempotency_keys'],
        );

        $documentsIdx = array_search('documents', $order, true);
        $idempotencyIdx = array_search('document_idempotency_keys', $order, true);
        $this->assertNotFalse($documentsIdx, 'initiate targeting an existing document must lock the documents row.');
        $this->assertNotFalse($idempotencyIdx, 'initiate must lock the idempotency row.');
        $this->assertLessThan($idempotencyIdx, $documentsIdx, 'documents must be locked before document_idempotency_keys.');
    }


    private function hashFor(string $filename, int $sizeBytes): string
    {
        return hash('sha256', $filename.':'.$sizeBytes);
    }

    private function properties(string $filename, int $sizeBytes = 512): StoredObjectProperties
    {
        return new StoredObjectProperties(
            $this->hashFor($filename, $sizeBytes),
            $sizeBytes,
            'application/pdf',
            'regression-etag',
            'regression-generation',
        );
    }

    private function seedTask(string $assignee, string $status, int $lockVersion): string
    {
        $id = (string) Str::uuid7();
        DB::table('tasks')->insert([
            'id' => $id,
            'title' => 'Regression task',
            'description' => null,
            'created_by_user_id' => $this->userId,
            'assignee_user_id' => $assignee,
            'owner_organization_unit_id' => $this->facilityId,
            'status' => $status,
            'priority' => 'normal',
            'classification' => 'internal',
            'completion_policy' => 'direct',
            'lock_version' => $lockVersion,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** @return array<string, string> */
    private function initiatePayload(string $name): array
    {
        return [
            'purpose' => 'document_version',
            'name' => 'Document '.$name,
            'description' => 'Regression upload '.$name,
            'classification' => 'internal',
            'file_name' => $name.'.pdf',
            'content_type' => 'application/pdf',
            'byte_size' => 512,
            'sha256' => $this->hashFor($name.'.pdf', 512),
        ];
    }

    /** @return array<string, string> */
    private function initiatePayloadForExistingDocument(string $documentId, string $name): array
    {
        return [
            ...$this->initiatePayload($name),
            'document_id' => $documentId,
        ];
    }

    /** @param array<string, mixed> $payload @param array<string, string> $extraHeaders */
    private function documentRequest(string $method, array $payload = [], string $idempotencyKey = 'regression', array $extraHeaders = []): Request
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CORRELATION_ID' => self::CORRELATION_ID,
            'HTTP_IDEMPOTENCY_KEY' => $idempotencyKey,
        ];
        foreach ($extraHeaders as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create('/', $method, [], [], [], $server, json_encode($payload, JSON_THROW_ON_ERROR));
    }
    /**
     * Execute the callback while listening to the connection and capture the
     * order in which lockForUpdate queries touch the named tables. Returning
     * a list preserves the order in which each table is locked for the first
     * time so callers can assert the relative order across the transaction.
     *
     * @param list<string> $tables
     *
     * @return list<string>
     */
    private function captureLockOrderDuring(callable $callback, array $tables): array
    {
        $seen = array_fill_keys($tables, false);
        $order = [];
        $active = true;
        DB::listen(function ($query) use ($tables, &$seen, &$order, &$active): void {
            if (! $active) {
                return;
            }
            $sql = strtolower($query->sql);
            if (! str_contains($sql, 'for update')) {
                return;
            }
            foreach ($tables as $table) {
                if ($seen[$table]) {
                    continue;
                }
                if (str_contains($sql, $table)) {
                    $seen[$table] = true;
                    $order[] = $table;
                    break;
                }
            }
        });
        $callback();
        $active = false;

        return $order;
    }

    private function documentPrincipals(): WorkerPrincipalResolver
    {
        return new class implements WorkerPrincipalResolver
        {
            public function issue(array $principal): array
            {
                return ['access_token' => 'regression-token', 'expires_at' => '2026-07-18T00:00:00.000Z'];
            }

            public function resolve(Request $request): ?array
            {
                return ['user_id' => OptimisticConcurrencyRegressionTest::PRINCIPAL_ID, 'facility_id' => OptimisticConcurrencyRegressionTest::FACILITY_ID];
            }
        };
    }

    private function documentAccess(): DecideAccess
    {
        return new class implements DecideAccess
        {
            public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
            {
                return new AccessDecision('allow', $capability, $facts->resourceType, [], 'regression-policy', 'regression-facts', $facts->classification);
            }
        };
    }
}