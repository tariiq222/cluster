<?php

namespace Modules\Documents\Tests\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Documents\Application\DocumentAuthorizationRecordFactsBuilder;
use Modules\Documents\Features\DocumentLifecycle\Http\ListDocumentsController;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Tests\TestCase;

final class DocumentListingControllerTest extends TestCase
{
    use RefreshDatabase;

    public const FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000801';

    public const PRINCIPAL_ID = '018f6f7d-0c00-7000-8000-000000000802';

    public function test_listing_continues_past_denied_rows_and_keeps_cursor_monotonic(): void
    {
        foreach ([
            ['018f6f7d-0c00-7000-8000-000000000811', 'top_secret'],
            ['018f6f7d-0c00-7000-8000-000000000812', 'internal'],
            ['018f6f7d-0c00-7000-8000-000000000813', 'internal'],
        ] as [$id, $classification]) {
            DB::table('documents')->insert([
                'id' => $id,
                'public_id' => $id,
                'owner_organization_unit_id' => self::FACILITY_ID,
                'created_by_user_id' => self::PRINCIPAL_ID,
                'name' => 'Document '.$id,
                'description' => null,
                'classification' => $classification,
                'status' => 'active',
                'current_version_id' => null,
                'retention_until' => null,
                'retention_policy_key' => null,
                'legal_hold' => false,
                'legal_hold_reason' => null,
                'legal_hold_at' => null,
                'lock_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $controller = new ListDocumentsController(
            new class implements ResolveDevelopmentFixturePrincipal
            {
                public function issue(array $principal): array
                {
                    return ['access_token' => 'test-token', 'expires_at' => '2026-07-22T00:00:00Z'];
                }

                public function resolve(Request $request): array
                {
                    return ['user_id' => DocumentListingControllerTest::PRINCIPAL_ID, 'facility_id' => DocumentListingControllerTest::FACILITY_ID];
                }
            },
            new class implements DecideAccess
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
                    $allowed = $facts?->classification !== 'top_secret';

                    return new AccessDecision($allowed ? 'allow' : 'deny', $capability, 'document', [], 'test-policy', 'test-facts', (string) $facts?->classification);
                }
            },
            $this->app->make(DocumentAuthorizationRecordFactsBuilder::class),
        );

        $first = $controller($this->request(['limit' => 1]));
        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame('018f6f7d-0c00-7000-8000-000000000812', $first->getData(true)['items'][0]['id']);
        $this->assertSame('018f6f7d-0c00-7000-8000-000000000812', $first->getData(true)['next_cursor']);

        $second = $controller($this->request(['limit' => 1, 'cursor' => $first->getData(true)['next_cursor']]));
        $this->assertSame('018f6f7d-0c00-7000-8000-000000000813', $second->getData(true)['items'][0]['id']);
        $this->assertNull($second->getData(true)['next_cursor']);
    }

    private function request(array $query): Request
    {
        return Request::create('/api/v1/documents', 'GET', $query, [], [], [
            'HTTP_X_CORRELATION_ID' => '018f6f7d-0c00-7000-8000-000000000899',
        ]);
    }
}
