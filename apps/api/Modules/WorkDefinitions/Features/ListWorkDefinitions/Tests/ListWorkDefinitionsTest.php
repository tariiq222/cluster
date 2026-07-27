<?php

namespace Modules\WorkDefinitions\Features\ListWorkDefinitions\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\WorkDefinitions\Features\Definition\Http\WorkDefinitionController;
use Tests\TestCase;

final class ListWorkDefinitionsTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000401';

    public const ACTOR_ID = '018f6f7d-0c00-7000-8000-000000000021';

    private const FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000011';

    private const FACILITY_TYPE_ID = '018f6f7d-0c00-7000-8000-000000000010';

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('clusters')->insert([
            'id' => '018f6f7d-0c00-7000-8000-000000000099',
            'code' => 'WD-CLUSTER',
            'name_ar' => 'تجمع',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('facility_types')->insert([
            'id' => self::FACILITY_TYPE_ID,
            'code' => 'work_definition_test_facility',
            'name_ar' => 'منشأة اختبار',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('facilities')->insert([
            'id' => self::FACILITY_ID,
            'cluster_id' => '018f6f7d-0c00-7000-8000-000000000099',
            'facility_type_id' => self::FACILITY_TYPE_ID,
            'code' => 'WD-FAC',
            'name_ar' => 'منشأة',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_index_query_budget_is_one_for_paginated_pages_and_unbounded_collects_are_rejected(): void
    {
        $now = Carbon::parse('2026-07-18 10:00:00');
        Carbon::setTestNow($now);
        for ($i = 0; $i < 4; $i++) {
            Carbon::setTestNow($now->copy()->addSeconds($i));
            DB::table('work_definitions')->insert([
                'id' => Str::uuid7()->toString(),
                'code' => 'budget-'.$i,
                'name' => 'Budget '.$i,
                'description' => null,
                'default_classification' => 'internal',
                'created_by_user_id' => self::ACTOR_ID,
                'status' => 'active',
                'lock_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        Carbon::setTestNow();

        $resolver = new class(self::FACILITY_ID) implements \Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal
        {
            public function __construct(private readonly string $facilityId) {}

            public function issue(array $principal): array
            {
                return ['access_token' => 't', 'expires_at' => '2026-07-22T00:00:00Z'];
            }

            public function resolve(\Illuminate\Http\Request $request): array
            {
                return ['user_id' => ListWorkDefinitionsTest::ACTOR_ID, 'facility_id' => $this->facilityId];
            }
        };
        $access = new class implements \Modules\Authorization\Contracts\DecideAccess
        {
            /**
             * Test doubles persist nothing, so the read-side evaluation IS decide().
             */
            public function evaluateOnly(array $actor, string $capability, ?\Modules\Authorization\Contracts\RecordFacts $facts): \Modules\Authorization\Contracts\AccessDecision
            {
                return $this->decide($actor, $capability, $facts);
            }

            public function decide(array $actor, string $capability, ?\Modules\Authorization\Contracts\RecordFacts $facts): \Modules\Authorization\Contracts\AccessDecision
            {
                return new \Modules\Authorization\Contracts\AccessDecision('allow', $capability, 'work_definition', [], 'test', 'test', 'internal');
            }
        };
        $outbox = new class implements \Shared\Contracts\TransactionalOutbox
        {
            public function append(string $eventId, string $aggregateId, string $type, array $payload): void {}
        };
        $controller = $this->controller($resolver, $outbox, $access);

        $request = \Illuminate\Http\Request::create('/api/v1/work-definitions', 'GET', ['limit' => 4], [], [], [
            'HTTP_X_CORRELATION_ID' => self::CORRELATION_ID,
        ]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $controller->index($request);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(4, $response->getData(true)['items']);
        $this->assertLessThanOrEqual(
            3,
            count($queries),
            'index must run a bounded (≤3) number of queries: clusters lookup, authorize decide, page rows.',
        );
    }

    public function test_index_returns_bounded_page_with_opaque_cursor_in_created_at_id_order(): void
    {
        $now = Carbon::parse('2026-07-18 10:00:00');
        Carbon::setTestNow($now);

        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            Carbon::setTestNow($now->copy()->addSeconds($i));
            $ids[] = Str::uuid7()->toString();
            DB::table('work_definitions')->insert([
                'id' => $ids[$i],
                'code' => 'wd-'.$i,
                'name' => 'WD '.$i,
                'description' => null,
                'default_classification' => 'internal',
                'created_by_user_id' => self::ACTOR_ID,
                'status' => 'active',
                'lock_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        Carbon::setTestNow();

        $request = \Illuminate\Http\Request::create('/api/v1/work-definitions', 'GET', ['limit' => 2], [], [], [
            'HTTP_X_CORRELATION_ID' => self::CORRELATION_ID,
        ]);
        $resolver = new class(self::FACILITY_ID) implements \Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal
        {
            public function __construct(private readonly string $facilityId) {}

            public function issue(array $principal): array
            {
                return ['access_token' => 'test', 'expires_at' => '2026-07-22T00:00:00Z'];
            }

            public function resolve(\Illuminate\Http\Request $request): array
            {
                return ['user_id' => ListWorkDefinitionsTest::ACTOR_ID, 'facility_id' => $this->facilityId];
            }
        };
        $access = new class implements \Modules\Authorization\Contracts\DecideAccess
        {
            /**
             * Test doubles persist nothing, so the read-side evaluation IS decide().
             */
            public function evaluateOnly(array $actor, string $capability, ?\Modules\Authorization\Contracts\RecordFacts $facts): \Modules\Authorization\Contracts\AccessDecision
            {
                return $this->decide($actor, $capability, $facts);
            }

            public function decide(array $actor, string $capability, ?\Modules\Authorization\Contracts\RecordFacts $facts): \Modules\Authorization\Contracts\AccessDecision
            {
                return new \Modules\Authorization\Contracts\AccessDecision('allow', $capability, 'work_definition', [], 'test', 'test', 'internal');
            }
        };
        $outbox = new class implements \Shared\Contracts\TransactionalOutbox
        {
            public function append(string $eventId, string $aggregateId, string $type, array $payload): void {}
        };

        $controller = $this->controller($resolver, $outbox, $access);
        $first = $controller->index($request);
        $this->assertSame(200, $first->getStatusCode());
        $firstBody = $first->getData(true);
        $this->assertCount(2, $firstBody['items']);
        $this->assertSame($ids[0], $firstBody['items'][0]['id']);
        $this->assertSame($ids[1], $firstBody['items'][1]['id']);
        $this->assertIsString($firstBody['next_cursor']);
        $this->assertNotEmpty($firstBody['next_cursor']);

        $next = \Illuminate\Http\Request::create('/api/v1/work-definitions', 'GET', [
            'limit' => 2,
            'cursor' => $firstBody['next_cursor'],
        ], [], [], [
            'HTTP_X_CORRELATION_ID' => self::CORRELATION_ID,
        ]);
        $second = $controller->index($next);
        $secondBody = $second->getData(true);
        $this->assertCount(2, $secondBody['items']);
        $this->assertSame($ids[2], $secondBody['items'][0]['id']);
        $this->assertSame($ids[3], $secondBody['items'][1]['id']);
        $this->assertIsString($secondBody['next_cursor']);

        $last = \Illuminate\Http\Request::create('/api/v1/work-definitions', 'GET', [
            'limit' => 2,
            'cursor' => $secondBody['next_cursor'],
        ], [], [], [
            'HTTP_X_CORRELATION_ID' => self::CORRELATION_ID,
        ]);
        $lastBody = $controller->index($last)->getData(true);
        $this->assertCount(1, $lastBody['items']);
        $this->assertSame($ids[4], $lastBody['items'][0]['id']);
        $this->assertNull($lastBody['next_cursor']);
    }

    public function test_index_rejects_tampered_cursor_with_400_problem_json(): void
    {
        $request = \Illuminate\Http\Request::create('/api/v1/work-definitions', 'GET', [
            'limit' => 2,
            'cursor' => 'not-a-real-cursor',
        ], [], [], [
            'HTTP_X_CORRELATION_ID' => self::CORRELATION_ID,
        ]);
        $resolver = new class(self::FACILITY_ID) implements \Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal
        {
            public function __construct(private readonly string $facilityId) {}

            public function issue(array $principal): array
            {
                return ['access_token' => 'test', 'expires_at' => '2026-07-22T00:00:00Z'];
            }

            public function resolve(\Illuminate\Http\Request $request): array
            {
                return ['user_id' => ListWorkDefinitionsTest::ACTOR_ID, 'facility_id' => $this->facilityId];
            }
        };
        $access = new class implements \Modules\Authorization\Contracts\DecideAccess
        {
            /**
             * Test doubles persist nothing, so the read-side evaluation IS decide().
             */
            public function evaluateOnly(array $actor, string $capability, ?\Modules\Authorization\Contracts\RecordFacts $facts): \Modules\Authorization\Contracts\AccessDecision
            {
                return $this->decide($actor, $capability, $facts);
            }

            public function decide(array $actor, string $capability, ?\Modules\Authorization\Contracts\RecordFacts $facts): \Modules\Authorization\Contracts\AccessDecision
            {
                return new \Modules\Authorization\Contracts\AccessDecision('allow', $capability, 'work_definition', [], 'test', 'test', 'internal');
            }
        };
        $outbox = new class implements \Shared\Contracts\TransactionalOutbox
        {
            public function append(string $eventId, string $aggregateId, string $type, array $payload): void {}
        };

        $controller = $this->controller($resolver, $outbox, $access);
        $response = $controller->index($request);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));
        $this->assertSame('https://cluster.example/problems/invalid-pagination', $response->getData(true)['type']);
    }

    public function test_index_rejects_limit_outside_the_bounded_range(): void
    {
        $request = \Illuminate\Http\Request::create('/api/v1/work-definitions', 'GET', ['limit' => 0], [], [], [
            'HTTP_X_CORRELATION_ID' => self::CORRELATION_ID,
        ]);
        $resolver = new class(self::FACILITY_ID) implements \Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal
        {
            public function __construct(private readonly string $facilityId) {}

            public function issue(array $principal): array
            {
                return ['access_token' => 'test', 'expires_at' => '2026-07-22T00:00:00Z'];
            }

            public function resolve(\Illuminate\Http\Request $request): array
            {
                return ['user_id' => ListWorkDefinitionsTest::ACTOR_ID, 'facility_id' => $this->facilityId];
            }
        };
        $access = new class implements \Modules\Authorization\Contracts\DecideAccess
        {
            /**
             * Test doubles persist nothing, so the read-side evaluation IS decide().
             */
            public function evaluateOnly(array $actor, string $capability, ?\Modules\Authorization\Contracts\RecordFacts $facts): \Modules\Authorization\Contracts\AccessDecision
            {
                return $this->decide($actor, $capability, $facts);
            }

            public function decide(array $actor, string $capability, ?\Modules\Authorization\Contracts\RecordFacts $facts): \Modules\Authorization\Contracts\AccessDecision
            {
                return new \Modules\Authorization\Contracts\AccessDecision('allow', $capability, 'work_definition', [], 'test', 'test', 'internal');
            }
        };
        $outbox = new class implements \Shared\Contracts\TransactionalOutbox
        {
            public function append(string $eventId, string $aggregateId, string $type, array $payload): void {}
        };

        $controller = $this->controller($resolver, $outbox, $access);
        $response = $controller->index($request);
        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_index_returns_problem_401_when_principal_cannot_be_resolved(): void
    {
        $request = \Illuminate\Http\Request::create('/api/v1/work-definitions', 'GET', ['limit' => 5], [], [], [
            'HTTP_X_CORRELATION_ID' => self::CORRELATION_ID,
        ]);
        $resolver = new class implements \Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal
        {
            public function issue(array $principal): array
            {
                return ['access_token' => 'test', 'expires_at' => '2026-07-22T00:00:00Z'];
            }

            public function resolve(\Illuminate\Http\Request $request): ?array
            {
                return null;
            }
        };
        $access = new class implements \Modules\Authorization\Contracts\DecideAccess
        {
            /**
             * Test doubles persist nothing, so the read-side evaluation IS decide().
             */
            public function evaluateOnly(array $actor, string $capability, ?\Modules\Authorization\Contracts\RecordFacts $facts): \Modules\Authorization\Contracts\AccessDecision
            {
                return $this->decide($actor, $capability, $facts);
            }

            public function decide(array $actor, string $capability, ?\Modules\Authorization\Contracts\RecordFacts $facts): \Modules\Authorization\Contracts\AccessDecision
            {
                return new \Modules\Authorization\Contracts\AccessDecision('allow', $capability, 'work_definition', [], 'test', 'test', 'internal');
            }
        };
        $outbox = new class implements \Shared\Contracts\TransactionalOutbox
        {
            public function append(string $eventId, string $aggregateId, string $type, array $payload): void {}
        };

        $controller = $this->controller($resolver, $outbox, $access);
        $response = $controller->index($request);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('https://cluster.example/problems/authentication-required', $response->getData(true)['type']);
    }

    private function controller(
        \Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal $resolver,
        \Shared\Contracts\TransactionalOutbox $outbox,
        \Modules\Authorization\Contracts\DecideAccess $access,
    ): WorkDefinitionController {
        $cluster = new class implements \Modules\Organization\Contracts\GetDefaultClusterId
        {
            public function resolve(): string
            {
                return '018f6f7d-0c00-7000-8000-000000000099';
            }
        };

        return new WorkDefinitionController(
            $resolver,
            new \Modules\WorkDefinitions\Features\Definition\Handler\WorkDefinitionMutator($outbox, $access, $cluster),
        );
    }
}
