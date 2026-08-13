<?php

declare(strict_types=1);

namespace Modules\Documents\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Documents\Contracts\DocumentSourceReference;
use Modules\Documents\Contracts\LinkedResourceAuthorizationFacts;
use Tests\TestCase;

/**
 * The producer-module facts router: every module that links documents
 * (currently Tasks) registers its own LinkedResourceAuthorizationFacts
 * implementation under the shared container tag, and the Documents-owned
 * router must answer the right facts for each source without Documents
 * ever querying producer-owned tables.
 */
final class LinkedResourceFactsRouterTest extends TestCase
{
    use RefreshDatabase;

    public function test_router_resolves_task_facts_from_the_tasks_implementation(): void
    {
        $taskId = (string) Str::uuid7();
        DB::table('tasks')->insert([
            'id' => $taskId,
            'title' => 'Router task',
            'description' => null,
            'created_by_user_id' => '018f6f7d-0c00-7000-8000-000000000021',
            'assignee_user_id' => '018f6f7d-0c00-7000-8000-000000000022',
            'owner_organization_unit_id' => '018f6f7d-0c00-7000-8000-000000000011',
            'status' => 'open',
            'priority' => 'normal',
            'classification' => 'internal',
            'completion_policy' => 'direct',
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $facts = $this->app->make(LinkedResourceAuthorizationFacts::class)
            ->resolve(new DocumentSourceReference('tasks', 'task', $taskId));

        $this->assertNotNull($facts, 'The router must resolve task facts from the Tasks implementation.');
        $this->assertSame('task', $facts->resourceType);
        $this->assertSame('open', $facts->lifecycleState);
    }

    public function test_router_returns_null_for_unknown_sources(): void
    {
        $facts = $this->app->make(LinkedResourceAuthorizationFacts::class)
            ->resolve(new DocumentSourceReference('unknown-module', 'unknown-type', (string) Str::uuid7()));

        $this->assertNull($facts);
    }
}
