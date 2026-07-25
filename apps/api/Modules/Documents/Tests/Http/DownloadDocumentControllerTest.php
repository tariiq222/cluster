<?php

namespace Modules\Documents\Tests\Http;

use Illuminate\Http\Request;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Documents\Application\DocumentDownloadService;
use Modules\Documents\Contracts\DocumentDownloadGrantIssuer;
use Modules\Documents\Contracts\LinkedResourceAuthorizationFacts;
use Modules\Documents\Contracts\SensitiveAccessEventRecorder;
use Modules\Documents\Features\DocumentDownload\Http\DownloadDocumentController;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Tests\TestCase;

final class DownloadDocumentControllerTest extends TestCase
{
    public function test_download_requires_correlation_id(): void
    {
        $principals = new class implements ResolveDevelopmentFixturePrincipal
        {
            public function issue(array $principal): array
            {
                return ['access_token' => 'test-token', 'expires_at' => '2026-07-19T16:00:00Z'];
            }

            public function resolve(Request $request): ?array
            {
                return $request->headers->has('X-Deny-Test')
                    ? null
                    : ['user_id' => '018f6f7d-0c00-7000-8000-000000000805', 'facility_id' => '018f6f7d-0c00-7000-8000-000000000806'];
            }
        };
        $service = new DocumentDownloadService($this->createMock(DecideAccess::class), $this->createMock(LinkedResourceAuthorizationFacts::class), $this->createMock(DocumentDownloadGrantIssuer::class), $this->createMock(SensitiveAccessEventRecorder::class));
        $response = (new DownloadDocumentController($principals, $service))(Request::create('/', 'GET'), '018f6f7d-0c00-7000-8000-000000000801');
        $this->assertSame(400, $response->getStatusCode());
    }
}
