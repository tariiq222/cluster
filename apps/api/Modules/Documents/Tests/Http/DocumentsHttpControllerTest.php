<?php

namespace Modules\Documents\Tests\Http;

use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Documents\Application\DocumentDownloadGrant;
use Modules\Documents\Application\DocumentMutationHandler;
use Modules\Documents\Application\QuarantineObjectReference;
use Modules\Documents\Application\StoredObjectProperties;
use Modules\Documents\Application\VerifiedQuarantineObject;
use Modules\Documents\Contracts\DocumentDownloadGrantIssuer;
use Modules\Documents\Contracts\WorkerPrincipalResolver;
use Modules\Documents\Domain\DocumentRetentionPolicy;
use Modules\Documents\Domain\DocumentUploadPolicy;
use Modules\Documents\Features\DocumentGrant\Http\CreateDocumentGrantController;
use Modules\Documents\Features\DocumentVersion\Http\AddDocumentVersionController;
use Modules\Documents\Features\DocumentVersion\Http\ReconcileDocumentPromotionController;
use Modules\Documents\Features\DocumentVersion\Http\ScanDocumentVersionController;
use Modules\Documents\Features\Upload\DocumentUploadHandler;
use Modules\Documents\Features\Upload\Http\CompleteDocumentUploadController;
use Modules\Documents\Features\Upload\Http\GetDocumentUploadStatusController;
use Modules\Documents\Features\Upload\Http\InitiateDocumentUploadController;
use Modules\Documents\Infrastructure\Persistence\DatabaseDocumentAuthorizationFactsReader;
use Modules\Documents\Infrastructure\Persistence\DatabaseDocumentUploadStatusReader;
use Modules\Documents\Infrastructure\Security\UnavailableMalwareScanner;
use Modules\Documents\Infrastructure\Storage\UnavailablePrivateObjectStorage;
use Modules\Documents\Tests\Support\InMemoryMalwareScanner;
use Modules\Documents\Tests\Support\InMemoryPrivateObjectStorage;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use RuntimeException;
use Shared\Contracts\TransactionalOutbox;
use Tests\TestCase;

final class DocumentsHttpControllerTest extends TestCase
{
    use RefreshDatabase;

    public const FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000801';

    public const PRINCIPAL_ID = '018f6f7d-0c00-7000-8000-000000000802';

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000899';

    private InMemoryPrivateObjectStorage $storage;

    private InMemoryMalwareScanner $scanner;

    private ResolveDevelopmentFixturePrincipal $principals;

    private DecideAccess $access;

    /** @var array{user_id: string, facility_id: string}|null */
    private ?array $resolvedPrincipal = null;

    private bool $accessAllowed = true;

    private InitiateDocumentUploadController $initiate;

    private CompleteDocumentUploadController $complete;

    private AddDocumentVersionController $addVersion;

    private GetDocumentUploadStatusController $status;

    private ScanDocumentVersionController $scan;

    private ReconcileDocumentPromotionController $reconcile;

    private CreateDocumentGrantController $grant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = new InMemoryPrivateObjectStorage;
        $this->scanner = new InMemoryMalwareScanner;
        $handler = new DocumentUploadHandler(
            $this->storage,
            $this->scanner,
            DocumentUploadPolicy::fromConfig(config('documents')),
            DocumentRetentionPolicy::fromConfig(config('documents')),
            $this->app->make(TransactionalOutbox::class),
        );
        $this->resolvedPrincipal = ['user_id' => self::PRINCIPAL_ID, 'facility_id' => self::FACILITY_ID];
        $this->principals = new class($this) implements WorkerPrincipalResolver
        {
            public function __construct(private readonly DocumentsHttpControllerTest $test) {}

            public function issue(array $principal): array
            {
                return ['access_token' => 'test-token', 'expires_at' => '2026-07-18T00:00:00.000Z'];
            }

            public function resolve(Request $request): ?array
            {
                return $this->test->resolvedPrincipal();
            }
        };
        $this->access = new class($this) implements DecideAccess
        {
            public function __construct(private readonly DocumentsHttpControllerTest $test) {}

            /**
             * Test doubles persist nothing, so the read-side evaluation IS decide().
             */
            public function evaluateOnly(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
            {
                return $this->decide($actor, $capability, $facts);
            }

            public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
            {
                return new AccessDecision(
                    $this->test->allowsAccess() ? 'allow' : 'deny',
                    $capability,
                    $facts->resourceType,
                    [],
                    'test-policy-v1',
                    'test-facts-v1',
                    $facts->classification,
                );
            }
        };
        $authorizationFacts = new DatabaseDocumentAuthorizationFactsReader;
        $reader = new DatabaseDocumentUploadStatusReader;
        $this->initiate = new InitiateDocumentUploadController($this->principals, $this->access, $handler);
        $this->complete = new CompleteDocumentUploadController($this->principals, $this->access, $authorizationFacts, $handler);
        $this->addVersion = new AddDocumentVersionController($this->principals, $this->access, $handler);
        $this->status = new GetDocumentUploadStatusController($this->principals, $this->access, $authorizationFacts, $reader);
        $this->scan = new ScanDocumentVersionController($this->principals, $this->access, $authorizationFacts, $handler);
        $this->reconcile = new ReconcileDocumentPromotionController($this->principals, $this->access, $authorizationFacts, $handler);
        $this->grant = new CreateDocumentGrantController($this->principals, $this->access, new class implements DocumentDownloadGrantIssuer
        {
            public function issue(string $documentId, string $versionId, string $principalId): DocumentDownloadGrant
            {
                return new DocumentDownloadGrant($documentId, $versionId, 'https://download.invalid/'.$versionId, new DateTimeImmutable('+5 minutes'), 'test-correlation');
            }
        }, $this->app->make(DocumentMutationHandler::class));
    }

    public function test_grants_only_available_versions_belonging_to_the_document(): void
    {
        ($this->initiate)($this->jsonRequest('POST', $this->initiatePayload('grant-state'), 'grant-state-initiate'));
        $documentId = (string) DB::table('documents')->value('public_id');
        $versionId = (string) DB::table('document_versions')->value('public_id');

        $rejected = ($this->grant)($this->jsonRequest('POST', ['version_id' => $versionId], 'grant-state-rejected'), $documentId, 'download');
        $this->assertSame(Response::HTTP_CONFLICT, $rejected->getStatusCode());
        $this->assertSame(0, DB::table('document_idempotency_keys')->where('operation', 'documents.download-grant')->count());

        DB::table('document_versions')->where('public_id', $versionId)->update(['availability_status' => 'available']);
        $allowed = ($this->grant)($this->jsonRequest('POST', ['version_id' => $versionId], 'grant-state-available'), $documentId, 'download');
        $this->assertSame(Response::HTTP_CREATED, $allowed->getStatusCode());
        $this->assertSame($versionId, $allowed->getData(true)['data']['version_id']);
        $replayed = ($this->grant)($this->jsonRequest('POST', ['version_id' => $versionId], 'grant-state-available'), $documentId, 'download');
        $this->assertSame($allowed->getData(true), $replayed->getData(true));
        $this->assertSame(1, DB::table('document_idempotency_keys')->where('operation', 'documents.download-grant')->count());
        $this->assertSame(1, DB::table('document_access_events')->where('action', 'download-grant')->count());
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'com.cluster.documents.grantissued.v1')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'documents.grant.issued')->count(), 'Controller equal replay must not invoke the Audit recorder again.');
    }

    public function test_it_initiates_an_opaque_signed_quarantine_upload_from_the_trusted_principal_only(): void
    {
        $response = ($this->initiate)($this->jsonRequest('POST', $this->initiatePayload('initiate-a'), 'initiate-a'));

        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $this->assertSame(self::CORRELATION_ID, $response->headers->get('X-Correlation-ID'));
        $payload = $response->getData(true);
        $this->assertArrayHasKey('upload_id', $payload);
        $this->assertArrayHasKey('quarantine_object_id', $payload);
        $this->assertSame('document_version', $payload['purpose']);
        $this->assertSame('PUT', $payload['method']);
        $this->assertSame([
            'Content-Length' => '512',
            'Content-Type' => 'application/pdf',
            'x-amz-checksum-sha256' => base64_encode(hex2bin($this->hashFor('initiate-a.pdf', 512))),
            'If-None-Match' => '*',
        ], $payload['required_headers']);
        $this->assertSame((int) config('documents.uploads.max_size_bytes'), $payload['max_size_bytes']);
        $this->assertArrayNotHasKey('object_key', $payload);
        $this->assertArrayNotHasKey('storage_object_id', $payload);
        $this->assertArrayNotHasKey('scan_engine', $payload);

        $replayed = ($this->initiate)($this->jsonRequest('POST', $this->initiatePayload('initiate-a'), 'initiate-a'));
        $this->assertSame($payload, $replayed->getData(true));
        $this->assertSame(1, $this->storage->issuedIntentCalls);
    }

    public function test_it_rejects_actor_fields_and_conflicting_idempotency_without_creating_another_upload(): void
    {
        ($this->initiate)($this->jsonRequest('POST', $this->initiatePayload('idempotency-a'), 'same-key'));
        $conflict = ($this->initiate)($this->jsonRequest('POST', $this->initiatePayload('idempotency-b'), 'same-key'));

        $this->assertProblem($conflict, Response::HTTP_CONFLICT, 'idempotency-conflict');
        $this->assertSame(1, $this->storage->issuedIntentCalls);

        $payload = $this->initiatePayload('untrusted-fields');
        $payload['principal_id'] = '018f6f7d-0c00-7000-8000-000000000803';
        $payload['organization_unit_id'] = '018f6f7d-0c00-7000-8000-000000000804';
        $invalid = ($this->initiate)($this->jsonRequest('POST', $payload, 'untrusted-fields'));

        $this->assertProblem($invalid, Response::HTTP_BAD_REQUEST, 'invalid-document-upload');
    }

    public function test_it_requires_a_correlation_id_authenticated_principal_and_authorization_decision(): void
    {
        $missingCorrelation = ($this->initiate)($this->jsonRequest('POST', $this->initiatePayload('missing-correlation'), 'missing-correlation', null));
        $this->assertSame(Response::HTTP_BAD_REQUEST, $missingCorrelation->getStatusCode());
        $this->assertSame('https://cluster.example/problems/invalid-correlation-id', $missingCorrelation->getData(true)['type']);

        $this->resolvedPrincipal = null;
        $unauthenticated = ($this->initiate)($this->jsonRequest('POST', $this->initiatePayload('unauthenticated'), 'unauthenticated'));
        $this->assertProblem($unauthenticated, Response::HTTP_UNAUTHORIZED, 'authentication-required');

        $this->resolvedPrincipal = ['user_id' => self::PRINCIPAL_ID, 'facility_id' => self::FACILITY_ID];
        $this->accessAllowed = false;
        $denied = ($this->initiate)($this->jsonRequest('POST', $this->initiatePayload('denied'), 'denied'));
        $this->assertProblem($denied, Response::HTTP_FORBIDDEN, 'access-denied');
    }

    public function test_it_completes_and_reports_upload_status_without_object_or_av_internals(): void
    {
        $started = ($this->initiate)($this->jsonRequest('POST', $this->initiatePayload('complete'), 'complete-initiate'))->getData(true);
        $this->storage->completeUpload($started['upload_id'], $this->properties('complete.pdf', 512));

        $completed = ($this->complete)($this->jsonRequest('POST', [
            'sha256' => $this->hashFor('complete.pdf', 512),
            'byte_size' => 512,
        ], 'complete'), $started['upload_id']);
        $this->assertSame(Response::HTTP_ACCEPTED, $completed->getStatusCode());
        $this->assertTrue($completed->getData(true)['accepted']);
        $this->assertSame('quarantined', $completed->getData(true)['availability_status']);

        $status = ($this->status)($this->jsonRequest('GET'), $started['upload_id']);
        $this->assertSame(Response::HTTP_OK, $status->getStatusCode());
        $payload = $status->getData(true);
        $this->assertMatchesRegularExpression('/\A[0-9a-f-]{36}\z/', $payload['document_id']);
        $this->assertMatchesRegularExpression('/\A[0-9a-f-]{36}\z/', $payload['version_id']);
        $this->assertSame('application/pdf', $payload['detected_mime_type']);
        $this->assertSame(512, $payload['byte_size']);
        $this->assertSame($this->hashFor('complete.pdf', 512), $payload['sha256']);
        $this->assertArrayNotHasKey('object_key', $payload);
        $this->assertArrayNotHasKey('storage_object_id', $payload);
        $this->assertArrayNotHasKey('scan_engine', $payload);
        $this->assertArrayNotHasKey('scan_signature_version', $payload);
        $this->assertArrayNotHasKey('scanner_outcome', $payload);
    }

    public function test_it_starts_a_new_version_on_an_existing_document_through_the_same_quarantine_flow(): void
    {
        ($this->initiate)($this->jsonRequest('POST', $this->initiatePayload('version-parent'), 'version-parent-initiate'));
        $documentId = (string) DB::table('documents')->value('public_id');
        $hash = $this->hashFor('version-two.pdf', 1024);

        $response = ($this->addVersion)($this->jsonRequest('POST', [
            'file_name' => 'version-two.pdf',
            'content_type' => 'application/pdf',
            'byte_size' => 1024,
            'sha256' => $hash,
        ], 'version-two'), $documentId);

        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertSame('document_version', $payload['purpose']);
        $this->assertArrayHasKey('upload_id', $payload);
        $this->assertSame(1, DB::table('documents')->count());
        $this->assertSame(2, DB::table('document_versions')->count());
        $this->assertSame($documentId, DB::table('document_upload_intents as intents')
            ->join('documents', 'documents.id', '=', 'intents.document_id')
            ->where('intents.id', $payload['upload_id'])
            ->value('documents.public_id'));
        $replayed = ($this->addVersion)($this->jsonRequest('POST', [
            'file_name' => 'version-two.pdf',
            'content_type' => 'application/pdf',
            'byte_size' => 1024,
            'sha256' => $hash,
        ], 'version-two'), $documentId);
        $this->assertSame($payload, $replayed->getData(true));
        $this->assertSame(2, DB::table('document_versions')->count());
        $this->storage->completeUpload($payload['upload_id'], $this->properties('version-two.pdf', 1024));
        $completed = ($this->complete)($this->jsonRequest('POST', [
            'sha256' => $hash,
            'byte_size' => 1024,
        ], 'version-two-complete'), $payload['upload_id']);
        $this->assertSame(Response::HTTP_ACCEPTED, $completed->getStatusCode());
        $this->assertTrue($completed->getData(true)['accepted']);
    }

    public function test_internal_scan_and_reconciliation_actions_are_idempotent_and_never_expose_av_details(): void
    {
        $started = ($this->initiate)($this->jsonRequest('POST', $this->initiatePayload('scan'), 'scan-initiate'))->getData(true);
        $this->storage->completeUpload($started['upload_id'], $this->properties('scan.pdf', 512));
        ($this->complete)($this->jsonRequest('POST', [
            'sha256' => $this->hashFor('scan.pdf', 512),
            'byte_size' => 512,
        ], 'scan-complete'), $started['upload_id']);
        $versionId = ($this->status)($this->jsonRequest('GET'), $started['upload_id'])->getData(true)['version_id'];

        $scan = ($this->scan)($this->jsonRequest('POST', [], 'scan-version'), $versionId);
        $this->assertSame(Response::HTTP_ACCEPTED, $scan->getStatusCode());
        $this->assertSame('clean', $scan->getData(true)['scan_status']);
        $this->assertSame('promotion_pending', $scan->getData(true)['availability_status']);
        $this->assertArrayNotHasKey('scan_engine', $scan->getData(true));

        $available = ($this->reconcile)($this->jsonRequest('POST', [], 'reconcile-promotion'), $versionId);
        $this->assertSame(Response::HTTP_OK, $available->getStatusCode());
        $this->assertSame('available', $available->getData(true)['availability_status']);
        $replayed = ($this->reconcile)($this->jsonRequest('POST', [], 'reconcile-promotion'), $versionId);
        $this->assertSame($available->getData(true), $replayed->getData(true));
        $this->assertSame(1, $this->storage->promotionCalls);
    }

    public function test_unavailable_production_storage_and_scanner_adapters_fail_closed(): void
    {
        $storage = new UnavailablePrivateObjectStorage;
        try {
            $storage->inspectQuarantineObject(new QuarantineObjectReference('018f6f7d-0c00-7000-8000-000000000805'));
            $this->fail('Unavailable production storage must not report an object as available.');
        } catch (RuntimeException $exception) {
            $this->assertSame('documents_private_storage_unavailable', $exception->getMessage());
        }

        $result = (new UnavailableMalwareScanner)->scan(new VerifiedQuarantineObject(
            new QuarantineObjectReference('018f6f7d-0c00-7000-8000-000000000805'),
            new StoredObjectProperties(
                $this->hashFor('scanner.pdf', 512),
                512,
                'application/pdf',
                'etag-test',
                'generation-test',
            ),
        ));
        $this->assertSame('unavailable', $result->outcome);
        $this->assertSame('scanner_unavailable', $result->reasonCode);
    }

    public function test_internal_scan_and_reconcile_controllers_reject_a_user_only_principal_resolver(): void
    {
        $authorizationFacts = new DatabaseDocumentAuthorizationFactsReader;
        $handler = new DocumentUploadHandler(
            new InMemoryPrivateObjectStorage,
            new InMemoryMalwareScanner,
            DocumentUploadPolicy::fromConfig(config('documents')),
            DocumentRetentionPolicy::fromConfig(config('documents')),
            $this->app->make(TransactionalOutbox::class),
        );
        $userOnlyPrincipal = new class implements ResolveDevelopmentFixturePrincipal
        {
            public function issue(array $principal): array
            {
                return ['access_token' => 'x', 'expires_at' => '2026-07-18T00:00:00.000Z'];
            }

            public function resolve(Request $request): ?array
            {
                return null;
            }
        };
        $access = new class implements DecideAccess
        {
            /**
             * Test doubles persist nothing, so the read-side evaluation IS decide().
             */
            public function evaluateOnly(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
            {
                return $this->decide($actor, $capability, $facts);
            }

            public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
            {
                return new AccessDecision('allow', $capability, $facts->resourceType, [], 'v', 'v', $facts->classification);
            }
        };

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('document_internal_endpoint_requires_worker_resolver');
        new ScanDocumentVersionController($userOnlyPrincipal, $access, $authorizationFacts, $handler);
    }

    /** @return array{purpose: string, name: string, description: string, classification: string, file_name: string, content_type: string, byte_size: int, sha256: string} */
    private function initiatePayload(string $name): array
    {
        return [
            'purpose' => 'document_version',
            'name' => 'Document '.$name,
            'description' => 'Governed upload '.$name,
            'classification' => 'internal',
            'file_name' => $name.'.pdf',
            'content_type' => 'application/pdf',
            'byte_size' => 512,
            'sha256' => $this->hashFor($name.'.pdf', 512),
        ];
    }

    private function jsonRequest(string $method, array $payload = [], string $idempotencyKey = 'status', ?string $correlationId = self::CORRELATION_ID): Request
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($correlationId !== null) {
            $server['HTTP_X_CORRELATION_ID'] = $correlationId;
        }
        if ($idempotencyKey !== '') {
            $server['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey;
        }

        return Request::create('/', $method, [], [], [], $server, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function properties(string $filename, int $sizeBytes): StoredObjectProperties
    {
        return new StoredObjectProperties(
            $this->hashFor($filename, $sizeBytes),
            $sizeBytes,
            'application/pdf',
            'etag-'.substr($this->hashFor($filename, $sizeBytes), 0, 16),
            'generation-'.substr($this->hashFor($filename, $sizeBytes), 0, 12),
        );
    }

    private function hashFor(string $filename, int $sizeBytes): string
    {
        return hash('sha256', $filename.':'.$sizeBytes);
    }

    private function assertProblem(mixed $response, int $status, string $type): void
    {
        $this->assertSame($status, $response->getStatusCode());
        $this->assertSame('https://cluster.example/problems/'.$type, $response->getData(true)['type']);
        $this->assertSame(self::CORRELATION_ID, $response->headers->get('X-Correlation-ID'));
        $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));
    }

    /** @return array{user_id: string, facility_id: string}|null */
    public function resolvedPrincipal(): ?array
    {
        return $this->resolvedPrincipal;
    }

    public function allowsAccess(): bool
    {
        return $this->accessAllowed;
    }
}
