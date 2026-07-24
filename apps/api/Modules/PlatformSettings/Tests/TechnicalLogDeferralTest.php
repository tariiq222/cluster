<?php

namespace Modules\PlatformSettings\Tests;

if (! class_exists('Modules\\PlatformSettings\\Tests\\DeferredAccessDecision')) {
    class_alias(\Modules\Authorization\Contracts\AccessDecision::class, 'Modules\\PlatformSettings\\Tests\\DeferredAccessDecision');
}
if (! class_exists('Modules\\PlatformSettings\\Tests\\DeferredDecideAccess')) {
    class_alias(\Modules\Authorization\Contracts\DecideAccess::class, 'Modules\\PlatformSettings\\Tests\\DeferredDecideAccess');
}
if (! class_exists('Modules\\PlatformSettings\\Tests\\DeferredRecordFacts')) {
    class_alias(\Modules\Authorization\Contracts\RecordFacts::class, 'Modules\\PlatformSettings\\Tests\\DeferredRecordFacts');
}
if (! class_exists('Modules\\PlatformSettings\\Tests\\DeferredResolveDevelopmentFixturePrincipal')) {
    class_alias(\Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal::class, 'Modules\\PlatformSettings\\Tests\\DeferredResolveDevelopmentFixturePrincipal');
}

use App\Integrations\PlatformOperations\MockTechnicalLogSource;
use App\Integrations\PlatformOperations\TechnicalLogSourceUnavailable;
use App\Integrations\PlatformOperations\UnavailableTechnicalLogSource;
use App\Integrations\PlatformSettings\PlatformSettingsApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\PlatformSettings\Contracts\TechnicalLogArchive;
use Modules\PlatformSettings\Contracts\TechnicalLogSource;
use Modules\PlatformSettings\Domain\TechnicalLogEntry;
use Modules\PlatformSettings\Domain\TechnicalLogFilter;
use Modules\PlatformSettings\Domain\TechnicalLogPage;
use Modules\PlatformSettings\Features\Logs\Handler\TechnicalLogsHandler;
use Modules\PlatformSettings\Features\Logs\Http\TechnicalLogsController;
use Tests\TestCase;

/**
 * Proves the technical-logs capability is DEFERRED in production.
 *
 * Contract:
 * - The production binding for `TechnicalLogSource` is the unavailable sentinel
 *   document; it never returns mock log entries to live traffic.
 * - Explicit test configuration may still bind `MockTechnicalLogSource`.
 */
final class TechnicalLogDeferralTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '0197f0e0-0000-7000-8000-000000000821';

    public function test_production_binding_resolves_to_the_unavailable_sentinel(): void
    {
        $source = $this->app->make(TechnicalLogSource::class);

        $this->assertInstanceOf(UnavailableTechnicalLogSource::class, $source);
    }

    public function test_unavailable_source_throws_typed_exception_when_searched(): void
    {
        $this->expectException(TechnicalLogSourceUnavailable::class);
        (new UnavailableTechnicalLogSource)->search(new TechnicalLogFilter(perPage: 10));
    }

    public function test_unavailable_source_returns_503_problem_document(): void
    {
        $controller = $this->makeController();

        $response = $controller->index($this->authorizedRequest('GET', '/platform-operations/technical-logs'));

        $this->assertSame(503, $response->getStatusCode());
        $body = $response->getData(true);
        $this->assertSame('https://cluster.example/problems/service-unavailable', $body['type']);
        $this->assertSame('Technical logs are not available in this environment.', $body['detail']);
    }

    public function test_restore_endpoint_returns_503_when_source_is_unavailable(): void
    {
        $controller = $this->makeController();
        $request = $this->authorizedRequest('POST', '/platform-operations/technical-logs/restore');
        $request->headers->set('Idempotency-Key', 'deferred-restore');
        $request->merge([
            'manifest_id' => '019f8e3b-3368-7192-85a6-3da3949fd711',
            'reason' => 'Investigate incident',
        ]);

        $response = $controller->restore($request);

        $this->assertSame(503, $response->getStatusCode());
        $body = $response->getData(true);
        $this->assertSame('https://cluster.example/problems/service-unavailable', $body['type']);
    }

    public function test_index_response_shape_matches_the_documented_contract_when_source_is_available(): void
    {
        $entries = [
            new TechnicalLogEntry('entry-001', 'security', 'security', new \DateTimeImmutable('2026-07-23T00:00:00+03:00'), 'corr-entry-001', []),
        ];
        $source = new class($entries) implements TechnicalLogSource
        {
            public function __construct(private readonly array $entries) {}

            public function isAvailable(): bool
            {
                return true;
            }

            public function search(TechnicalLogFilter $filter): TechnicalLogPage
            {
                return new TechnicalLogPage($this->entries, null);
            }
        };
        $api = new PlatformSettingsApi(
            new TechnicalLogDeferralFixturePrincipal,
            new TechnicalLogDeferralDecider,
        );
        $handler = new TechnicalLogsHandler($source, $this->createStub(TechnicalLogArchive::class));
        $controller = new TechnicalLogsController($api, $handler);

        $response = $controller->index($this->authorizedRequest('GET', '/platform-operations/technical-logs'));

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getData(true);
        $first = $body['items'][0];
        $this->assertSame('entry-001', $first['id']);
        $this->assertSame('security', $first['source']);
        $this->assertSame('security', $first['category']);
        $this->assertSame('info', $first['severity']);
        $this->assertSame('سجل فني', $first['message_ar']);
        $this->assertSame('Technical log entry', $first['message_en']);
        $this->assertSame('corr-entry-001', $first['correlation_id']);
    }

    public function test_explicit_test_configuration_may_bind_the_mock_source(): void
    {
        $this->app->instance(TechnicalLogSource::class, new MockTechnicalLogSource);

        $source = $this->app->make(TechnicalLogSource::class);

        $this->assertInstanceOf(MockTechnicalLogSource::class, $source);
    }

    private function makeController(): TechnicalLogsController
    {
        $api = new PlatformSettingsApi(
            new TechnicalLogDeferralFixturePrincipal,
            new TechnicalLogDeferralDecider,
        );
        $handler = new TechnicalLogsHandler(new UnavailableTechnicalLogSource, $this->createStub(TechnicalLogArchive::class));

        return new TechnicalLogsController($api, $handler);
    }

    private function authorizedRequest(string $method, string $uri): Request
    {
        $request = Request::create($uri, $method);
        $request->headers->set('X-Correlation-ID', self::CORRELATION_ID);
        $request->headers->set('Authorization', 'allow');

        return $request;
    }
}

final class TechnicalLogDeferralFixturePrincipal implements DeferredResolveDevelopmentFixturePrincipal
{
    public function issue(array $principal): array
    {
        return ['access_token' => 'test', 'expires_at' => now()->addHour()->toIso8601String()];
    }

    public function resolve(Request $request): ?array
    {
        if ($request->header('Authorization') === 'missing') {
            return null;
        }

        return [
            'user_id' => '0197f0e0-0000-7000-8000-000000000821',
            'facility_id' => '0197f0e0-0000-7000-8000-000000000822',
        ];
    }
}

final class TechnicalLogDeferralDecider implements DeferredDecideAccess
{
    public function decide(array $actor, string $capability, ?DeferredRecordFacts $facts): DeferredAccessDecision
    {
        return new DeferredAccessDecision(
            'allow',
            $capability,
            $facts->resourceType ?? 'platform_settings',
            [],
            'test',
            'test',
            'internal',
            allowedActions: ['create', 'update', 'validate', 'publish', 'restore'],
        );
    }
}
