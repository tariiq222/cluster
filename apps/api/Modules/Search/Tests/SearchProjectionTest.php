<?php

namespace Modules\Search\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Search\Features\IndexSourceEvent\Handler\IndexSourceEventHandler;
use Modules\Search\Features\RebuildSearchProjection\Handler\RebuildSearchProjectionHandler;
use Modules\Search\Features\SearchAccessibleRecords\Handler\SearchAccessibleRecordsHandler;
use Tests\TestCase;

final class SearchProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('search_index_entries')) {
            $this->artisan('migrate', ['--path' => 'Modules/Search/Infrastructure/Persistence/Migrations/CreateSearchProjectionTables.php', '--force' => true]);
        }
    }

    protected function migrateDatabases(): void
    {
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        $this->artisan('migrate', ['--path' => 'Modules/Search/Infrastructure/Persistence/Migrations/CreateSearchProjectionTables.php', '--force' => true]);
    }

    public function test_index_is_safe_and_rebuild_is_deterministic(): void
    {
        $event = $this->event('scope-a');
        $first = (new IndexSourceEventHandler)->handle($event);
        (new RebuildSearchProjectionHandler(new IndexSourceEventHandler))->handle([$event]);

        $row = DB::table('search_index_entries')->first();
        $this->assertSame($first['id'], $row->id);
        $this->assertSame('Public request', $row->title);
        $this->assertStringNotContainsString('SECRET', (string) $row->search_text);
        $this->assertArrayNotHasKey('payload', (array) $row);
    }

    public function test_denied_scope_is_absent_from_items_and_total(): void
    {
        $indexer = new IndexSourceEventHandler;
        $indexer->handle($this->event('scope-a'));
        $indexer->handle($this->event('scope-b'));

        $result = (new SearchAccessibleRecordsHandler(new ScopeDecider))->handle(['user_id' => 'u', 'facility_id' => 'scope-a'], 'request');
        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['items']);
        $this->assertSame('scope-a', $result['items'][0]['scope_id']);
    }

    /** @return array<string, mixed> */
    private function event(string $scope): array
    {
        return [
            'source_module' => 'WorkRecords', 'source_type' => 'work_record', 'source_id' => 'record-'.$scope, 'source_version' => 'v1',
            'scope_id' => $scope, 'classification' => 'internal', 'indexable' => ['title' => 'Public request', 'excerpt' => 'Safe excerpt', 'text' => 'Public request Safe excerpt'],
            'payload' => ['secret' => 'SECRET'],
        ];
    }
}

final class ScopeDecider implements DecideAccess
{
    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        $allowed = $facts !== null && ($actor['facility_id'] ?? null) === $facts->ownerFacilityId;

        return new AccessDecision($allowed ? 'allow' : 'deny', $capability, $facts === null ? 'work_record' : $facts->resourceType, [], 'test', 'test', $facts === null ? 'internal' : $facts->classification);
    }
}
