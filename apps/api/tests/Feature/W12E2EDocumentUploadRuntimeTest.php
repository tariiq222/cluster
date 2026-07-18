<?php

namespace Tests\Feature;

use App\Http\Controllers\Documents\DocumentsApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Documents\Application\DocumentAuthorizationFacts;
use Modules\Documents\Application\DocumentMetadata;
use Modules\Documents\Application\IdempotencyContext;
use Modules\Documents\Application\InitiateDocumentUpload;
use Modules\Documents\Application\UploadFileMetadata;
use Modules\Documents\Features\Upload\DocumentUploadHandler;
use Tests\TestCase;

final class W12E2EDocumentUploadRuntimeTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        $this->enableRealDocumentRuntime();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            if ($value === false) {
                unset($_ENV[$name], $_SERVER[$name]);
                putenv($name);
            } else {
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
                putenv("{$name}={$value}");
            }
        }

        parent::tearDown();
    }

    public function test_seeded_identity_session_can_initiate_the_browser_csv_upload_payload(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'W1.2 E2E test browser']);
        Artisan::call('e2e:w1-2:seed');
        $fixture = json_decode(trim(Artisan::output()), true, 16, JSON_THROW_ON_ERROR);
        $correlationId = '018f6f7d-0c00-7000-8000-000000000101';
        $login = $this->postJson('/api/v1/identity/login', [
            'username' => $fixture['identity_username'],
            'password' => $fixture['identity_password'],
        ], ['X-Correlation-ID' => $correlationId]);
        $login->assertOk();
        $this->assertCount(1, $login->headers->getCookies());
        $this->assertSame('cluster_identity_session', $login->headers->getCookies()[0]->getName());
        $cookie = $login->headers->getCookies()[0]->getValue();
        $csrf = $login->json('data.csrf_token');
        $csv = "employee_number,display_name_ar,status,position_id,start_at\nE2E-1,موظف اختبار,active,{$fixture['import_position_id']},2027-01-01T08:00:00Z\n";
        $payload = [
            'purpose' => 'organization_import_source',
            'name' => 'W1.2 browser CSV import',
            'description' => null,
            'classification' => 'confidential',
            'file_name' => 'w1-2-browser.csv',
            'content_type' => 'text/csv',
            'byte_size' => strlen($csv),
            'sha256' => hash('sha256', $csv),
        ];

        $actor = DocumentsApi::actorOrProblem(
            ['user_id' => '018f6f7d-0c00-7000-8000-000000000021', 'facility_id' => config('identity.authorization.default_organization_unit_id')],
            app(DecideAccess::class),
            new DocumentAuthorizationFacts((string) config('identity.authorization.default_organization_unit_id'), 'confidential'),
            DocumentUploadHandler::INITIATE_OPERATION,
            '018f6f7d-0c00-7000-8000-000000000103',
        );
        $this->assertNotInstanceOf(JsonResponse::class, $actor);
        try {
            app(DocumentUploadHandler::class)->initiate(
                $actor,
                new InitiateDocumentUpload(
                    $payload['purpose'],
                    new DocumentMetadata($payload['name'], $payload['description'], $payload['classification']),
                    new UploadFileMetadata($payload['file_name'], $payload['byte_size'], $payload['content_type'], $payload['sha256']),
                ),
                new IdempotencyContext($actor->principalId, DocumentUploadHandler::INITIATE_OPERATION, 'w12-e2e-direct-upload', hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR))),
            );
        } catch (\Throwable $exception) {
            $this->fail($exception::class.': '.$exception->getMessage());
        }

        $response = $this->withUnencryptedCookie('cluster_identity_session', $cookie)->withCredentials()->postJson('/api/v1/documents/uploads', $payload, [
            'X-Correlation-ID' => '018f6f7d-0c00-7000-8000-000000000102',
            'Idempotency-Key' => 'w12-e2e-browser-upload',
            'X-CSRF-Token' => $csrf,
        ]);

        $response->assertCreated()->assertJsonStructure([
            'upload_id', 'quarantine_object_id', 'upload_url', 'required_headers',
        ])->assertJsonPath('required_headers.x-amz-checksum-sha256', base64_encode(hex2bin($payload['sha256'])));
    }

    private function enableRealDocumentRuntime(): void
    {
        foreach ([
            'DOCUMENTS_TEST_RUNTIME_ENABLED' => 'true',
            'DOCUMENTS_UPLOAD_ENDPOINT_ALLOWLIST' => '127.0.0.1',
            'DOCUMENTS_S3_REGION' => 'us-east-1',
            'DOCUMENTS_S3_ENDPOINT' => 'http://127.0.0.1:9000',
            'DOCUMENTS_S3_USE_PATH_STYLE' => 'true',
            'DOCUMENTS_S3_QUARANTINE_BUCKET' => 'documents-quarantine',
            'DOCUMENTS_S3_AVAILABLE_BUCKET' => 'documents-available',
            'DOCUMENTS_S3_ACCESS_KEY_ID' => 'w12-e2e-test-key',
            'DOCUMENTS_S3_SECRET_ACCESS_KEY' => 'w12-e2e-test-secret',
            'DOCUMENTS_UPLOAD_INTENT_TTL_SECONDS' => '300',
            'DOCUMENTS_CLAMAV_TRANSPORT' => 'tcp',
            'DOCUMENTS_CLAMAV_HOST' => '127.0.0.1',
            'DOCUMENTS_CLAMAV_PORT' => '3310',
            'DOCUMENTS_CLAMAV_ENGINE_NAME' => 'clamav-e2e',
            'DOCUMENTS_CLAMAV_SIGNATURE_VERSION' => 'e2e-signatures',
        ] as $name => $value) {
            $this->originalEnvironment[$name] = getenv($name);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            putenv("{$name}={$value}");
        }
    }
}
