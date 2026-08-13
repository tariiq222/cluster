<?php

namespace Modules\Search\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Search\Features\IndexSourceEvent\Handler\IndexSourceEventHandler;
use Modules\Search\Features\Search\Http\SearchController;
use Modules\Search\Features\SearchAccessibleRecords\Handler\SearchAccessibleRecordsHandler;
use Tests\TestCase;

final class SearchHttpAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('search_index_entries')) {
            $this->artisan('migrate', ['--path' => 'Modules/Search/Infrastructure/Persistence/Migrations/CreateSearchProjectionTables.php', '--force' => true]);
        }
        if (! Schema::hasColumn('search_index_entries', 'status')) {
            $this->artisan('migrate', ['--path' => 'Modules/Search/Infrastructure/Persistence/Migrations/ZAddSearchIndexStatusColumn.php', '--force' => true]);
        }
    }

    public function test_post_search_returns_handler_shape_without_putting_query_in_the_url(): void
    {
        (new IndexSourceEventHandler)->handle([
            'source_module' => 'Documents', 'source_type' => 'document', 'source_id' => 'document-1', 'source_version' => 'v1',
            'scope_id' => 'scope-a', 'indexable' => ['title' => 'Visible request', 'text' => 'Visible request'],
        ]);
        $request = Request::create('/api/v1/search', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['q' => 'Visible'], JSON_THROW_ON_ERROR));
        $request->headers->set('X-Correlation-ID', '0197f0e0-0000-7000-8000-000000000001');
        $response = (new SearchController(new SearchPrincipalResolver, new SearchAccessibleRecordsHandler(new SearchAllowingDecider)))->__invoke($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $response->getData(true)['items']);
        $this->assertNull($response->getData(true)['next_cursor']);
        $this->assertSame('0197f0e0-0000-7000-8000-000000000001', $response->headers->get('X-Correlation-ID'));
    }

    public function test_post_search_returns_a_real_next_cursor_without_a_query_link_header(): void
    {
        $indexer = new IndexSourceEventHandler;
        foreach (['record-1', 'record-2', 'record-3'] as $index => $recordId) {
            $indexer->handle([
                'source_module' => 'Tasks', 'source_type' => 'task', 'source_id' => $recordId, 'source_version' => 'v1',
                'scope_id' => 'scope-a', 'indexable' => ['title' => 'Visible request '.$index, 'text' => 'Visible request '.$index],
            ]);
        }
        $request = Request::create('/api/v1/search', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['q' => 'Visible', 'limit' => 1], JSON_THROW_ON_ERROR));
        $request->headers->set('X-Correlation-ID', '0197f0e0-0000-7000-8000-000000000001');
        $response = (new SearchController(new SearchPrincipalResolver, new SearchAccessibleRecordsHandler(new SearchAllowingDecider)))->__invoke($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $response->getData(true)['items']);
        $cursor = $response->getData(true)['next_cursor'];
        $this->assertIsString($cursor);
        $this->assertFalse($response->headers->has('Link'));
    }

    public function test_post_search_rejects_a_malformed_cursor(): void
    {
        (new IndexSourceEventHandler)->handle([
            'source_module' => 'Tasks', 'source_type' => 'task', 'source_id' => 'record-1', 'source_version' => 'v1',
            'scope_id' => 'scope-a', 'indexable' => ['title' => 'Visible request', 'text' => 'Visible request'],
        ]);
        $request = Request::create('/api/v1/search', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['q' => 'Visible', 'cursor' => 'not-a-ciphertext'], JSON_THROW_ON_ERROR));
        $request->headers->set('X-Correlation-ID', '0197f0e0-0000-7000-8000-000000000001');
        $response = (new SearchController(new SearchPrincipalResolver, new SearchAccessibleRecordsHandler(new SearchAllowingDecider)))->__invoke($request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('https://cluster.example/problems/invalid-search-query', $response->getData(true)['type']);
    }

    public function test_post_search_filters_by_type_and_status(): void
    {
        (new IndexSourceEventHandler)->handle([
            'source_module' => 'Documents', 'source_type' => 'document', 'source_id' => 'document-1', 'source_version' => 'v1',
            'status' => 'submitted', 'scope_id' => 'scope-a', 'indexable' => ['title' => 'Draft request', 'text' => 'Draft request'],
        ]);
        (new IndexSourceEventHandler)->handle([
            'source_module' => 'Documents', 'source_type' => 'document', 'source_id' => 'document-2', 'source_version' => 'v1',
            'status' => 'draft', 'scope_id' => 'scope-a', 'indexable' => ['title' => 'Draft request', 'text' => 'Draft request'],
        ]);
        (new IndexSourceEventHandler)->handle([
            'source_module' => 'Tasks', 'source_type' => 'task', 'source_id' => 'task-1', 'source_version' => 'v1',
            'status' => 'submitted', 'scope_id' => 'scope-a', 'indexable' => ['title' => 'Draft request', 'text' => 'Draft request'],
        ]);

        $controller = new SearchController(new SearchPrincipalResolver, new SearchAccessibleRecordsHandler(new SearchAllowingDecider));

        $byType = Request::create('/api/v1/search', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['q' => 'Draft', 'type' => 'document'], JSON_THROW_ON_ERROR));
        $byType->headers->set('X-Correlation-ID', '0197f0e0-0000-7000-8000-000000000001');
        $typeResponse = $controller->__invoke($byType);
        $this->assertSame(200, $typeResponse->getStatusCode());
        $this->assertCount(2, $typeResponse->getData(true)['items']);

        $byStatus = Request::create('/api/v1/search', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['q' => 'Draft', 'type' => 'document', 'status' => 'draft'], JSON_THROW_ON_ERROR));
        $byStatus->headers->set('X-Correlation-ID', '0197f0e0-0000-7000-8000-000000000001');
        $statusResponse = $controller->__invoke($byStatus);
        $this->assertSame(200, $statusResponse->getStatusCode());
        $items = $statusResponse->getData(true)['items'];
        $this->assertCount(1, $items);
        $this->assertSame('document-2', $items[0]['source_id']);
    }
}

final class SearchPrincipalResolver implements ResolveDevelopmentFixturePrincipal
{
    public function issue(array $principal): array
    {
        return ['access_token' => 'test', 'expires_at' => now()->addHour()->toIso8601String()];
    }

    public function resolve(Request $request): ?array
    {
        return $request->header('Authorization') === 'missing' ? null : ['user_id' => 'user-1', 'facility_id' => 'scope-a'];
    }
}

final class SearchAllowingDecider implements DecideAccess
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
        return new AccessDecision('allow', $capability, $facts === null ? 'task' : $facts->resourceType, [], 'test', 'test', $facts === null ? 'internal' : $facts->classification);
    }
}
