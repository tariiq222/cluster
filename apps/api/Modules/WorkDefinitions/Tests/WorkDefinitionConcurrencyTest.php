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
use Shared\Contracts\TransactionalOutbox;
use Tests\TestCase;

final class WorkDefinitionConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const ACTOR_ID = '018f6f7d-0c00-7000-8000-000000000961';

    private const DEFINITION_ID = '018f6f7d-0c00-7000-8000-000000000962';

    private const VERSION_ID = '018f6f7d-0c00-7000-8000-000000000963';

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000964';

    public function test_transition_maps_a_stale_handler_cas_to_412_without_state_or_outbox_effects(): void
    {
        $this->seedVersion(2);
        $controller = new WorkDefinitionController(
            new WorkDefinitionConcurrencyPrincipalResolver,
            new WorkDefinitionMutator(
                $this->app->make(TransactionalOutbox::class),
                new WorkDefinitionConcurrencyDecision,
                new WorkDefinitionConcurrencyDefaultCluster,
            ),
        );
        $request = Request::create('/api/v1/work-definition-versions/'.self::VERSION_ID.'/test', 'POST');
        $request->headers->set('X-Correlation-ID', self::CORRELATION_ID);
        $request->headers->set('Idempotency-Key', 'stale-work-definition-transition');
        $request->headers->set('If-Match', '"1"');

        $response = $controller->transition($request, self::VERSION_ID, 'test');

        $this->assertSame(412, $response->getStatusCode());
        $this->assertSame('https://cluster.example/problems/precondition-failed', $response->getData(true)['type']);
        $this->assertSame('draft', DB::table('work_definition_versions')->where('id', self::VERSION_ID)->value('status'));
        $this->assertSame(2, (int) DB::table('work_definition_versions')->where('id', self::VERSION_ID)->value('lock_version'));
        $this->assertSame(0, DB::table('outbox_events')->where('event_type', 'work_definition.version.test.v1')->count());
    }

    private function seedVersion(int $lockVersion): void
    {
        $now = now();
        DB::table('work_definitions')->insert([
            'id' => self::DEFINITION_ID,
            'code' => 'stale-work-definition',
            'name' => 'Stale Work Definition',
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
            'status' => 'draft',
            'schema_document' => json_encode(['type' => 'object'], JSON_THROW_ON_ERROR),
            'field_policy_key' => 'work_definition.default',
            'schema_hash' => hash('sha256', json_encode(['type' => 'object'], JSON_THROW_ON_ERROR)),
            'change_summary' => null,
            'created_by_user_id' => self::ACTOR_ID,
            'lock_version' => $lockVersion,
            'published_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}

final class WorkDefinitionConcurrencyPrincipalResolver implements ResolveDevelopmentFixturePrincipal
{
    public function issue(array $principal): array
    {
        return ['access_token' => 'test', 'expires_at' => now()->addHour()->toIso8601String()];
    }

    public function resolve(Request $request): array
    {
        return ['user_id' => '018f6f7d-0c00-7000-8000-000000000961', 'facility_id' => '018f6f7d-0c00-7000-8000-000000000966'];
    }
}

final class WorkDefinitionConcurrencyDecision implements DecideAccess
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
        return new AccessDecision('allow', $capability, 'work_definition', [], 'test', 'test', 'internal');
    }
}

final class WorkDefinitionConcurrencyDefaultCluster implements GetDefaultClusterId
{
    public function resolve(): string
    {
        return '018f6f7d-0c00-7000-8000-000000000965';
    }
}
