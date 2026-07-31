<?php

namespace Modules\WorkDefinitions\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Contracts\GetDefaultClusterId;
use Modules\WorkDefinitions\Features\Definition\Handler\WorkDefinitionMutator;
use Modules\WorkDefinitions\Features\Definition\Http\WorkDefinitionController;
use PHPUnit\Framework\Attributes\DataProvider;
use Shared\Contracts\TransactionalOutbox;
use Tests\TestCase;

final class WorkDefinitionPublishTransitionTest extends TestCase
{
    use RefreshDatabase;

    private const ACTOR_ID = '018f6f7d-0c00-7000-8000-000000000971';

    private const DEFINITION_ID = '018f6f7d-0c00-7000-8000-000000000972';

    private const VERSION_ID = '018f6f7d-0c00-7000-8000-000000000973';

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000974';

    #[DataProvider('invalidPublishProvider')]
    public function test_publish_is_rejected_when_the_version_is_not_signed(string $status): void
    {
        $this->seedVersion($status, '2026-07-01 10:00:00');
        $controller = $this->controller();

        $response = $controller->transition(
            $this->request('publish'),
            self::VERSION_ID,
            'publish',
        );

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('https://cluster.example/problems/invalid-lifecycle-transition', $response->getData(true)['type']);
        $this->assertDatabaseHas('work_definition_versions', [
            'id' => self::VERSION_ID,
            'status' => $status,
            'lock_version' => 2,
        ]);
        $this->assertSame(0, DB::table('outbox_events')->where('event_type', 'work_definition.version.publish.v1')->count());
    }

    public static function invalidPublishProvider(): array
    {
        return [
            'a draft version cannot be published' => ['draft'],
            'a tested version cannot be published' => ['tested'],
            'an approved version cannot be published' => ['approved'],
            'an already published version cannot be re-published' => ['published'],
        ];
    }

    public function test_publish_from_signed_transitions_to_published(): void
    {
        $this->seedVersion('signed', null);
        $controller = $this->controller();

        $response = $controller->transition(
            $this->request('publish'),
            self::VERSION_ID,
            'publish',
        );

        $this->assertSame(200, $response->getStatusCode());
        $row = DB::table('work_definition_versions')->where('id', self::VERSION_ID)->first();
        $this->assertSame('published', $row->status);
        $this->assertNotNull($row->published_at);
        $this->assertSame(3, (int) $row->lock_version);
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'work_definition.version.publish.v1')->count());
    }

    public function test_republishing_does_not_bump_published_at(): void
    {
        $publishedAt = '2026-07-01 10:00:00';
        $this->seedVersion('published', $publishedAt);
        $controller = $this->controller();

        $response = $controller->transition(
            $this->request('publish'),
            self::VERSION_ID,
            'publish',
        );

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('https://cluster.example/problems/invalid-lifecycle-transition', $response->getData(true)['type']);
        $this->assertSame($publishedAt, DB::table('work_definition_versions')->where('id', self::VERSION_ID)->value('published_at'));
        $this->assertSame(2, (int) DB::table('work_definition_versions')->where('id', self::VERSION_ID)->value('lock_version'));
    }

    private function controller(): WorkDefinitionController
    {
        return new WorkDefinitionController(
            new WorkDefinitionPublishPrincipalResolver,
            new WorkDefinitionMutator(
                $this->app->make(TransactionalOutbox::class),
                new WorkDefinitionPublishDecision,
                new WorkDefinitionPublishDefaultCluster,
            ),
        );
    }

    private function request(string $action): Request
    {
        $request = Request::create('/api/v1/work-definition-versions/'.self::VERSION_ID.'/'.$action, 'POST');
        $request->headers->set('X-Correlation-ID', self::CORRELATION_ID);
        $request->headers->set('Idempotency-Key', 'publish-transition-'.self::VERSION_ID);
        $request->headers->set('If-Match', '"2"');

        return $request;
    }

    private function seedVersion(string $status, ?string $publishedAt): void
    {
        $now = now();
        DB::table('work_definitions')->insert([
            'id' => self::DEFINITION_ID,
            'code' => 'publish-guard',
            'name' => 'Publish Guard Definition',
            'description' => null,
            'default_classification' => 'internal',
            'created_by_user_id' => self::ACTOR_ID,
            'status' => 'active',
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('work_definition_versions')->insert([
            'id' => self::VERSION_ID,
            'work_definition_id' => self::DEFINITION_ID,
            'version_number' => 1,
            'status' => $status,
            'schema_document' => json_encode(['type' => 'object'], JSON_THROW_ON_ERROR),
            'field_policy_key' => 'work_definition.default',
            'schema_hash' => hash('sha256', json_encode(['type' => 'object'], JSON_THROW_ON_ERROR)),
            'change_summary' => null,
            'created_by_user_id' => self::ACTOR_ID,
            'lock_version' => 2,
            'published_at' => $publishedAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}

final class WorkDefinitionPublishPrincipalResolver implements ResolveDevelopmentFixturePrincipal
{
    public function issue(array $principal): array
    {
        return ['access_token' => 'test', 'expires_at' => now()->addHour()->toIso8601String()];
    }

    public function resolve(Request $request): array
    {
        return ['user_id' => '018f6f7d-0c00-7000-8000-000000000971', 'facility_id' => '018f6f7d-0c00-7000-8000-000000000976'];
    }
}

final class WorkDefinitionPublishDecision implements DecideAccess
{
    public function evaluateOnly(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        return $this->decide($actor, $capability, $facts);
    }

    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        return new AccessDecision('allow', $capability, 'work_definition', [], 'test', 'test', 'internal');
    }
}

final class WorkDefinitionPublishDefaultCluster implements GetDefaultClusterId
{
    public function resolve(): string
    {
        return '018f6f7d-0c00-7000-8000-000000000975';
    }
}
