<?php

namespace Modules\Search\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Search\Features\IndexSourceEvent\Handler\IndexSourceEventHandler;
use Modules\Search\Features\SearchAccessibleRecords\Handler\SearchAccessibleRecordsHandler;
use Tests\TestCase;

final class SearchAccessibleRecordsHandlerBoundTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<object{sql: string, bindings: array, time: float}> */
    private array $capturedSearchSelects = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->capturedSearchSelects = [];
        if (! Schema::hasTable('search_index_entries')) {
            $this->artisan('migrate', ['--path' => 'Modules/Search/Infrastructure/Persistence/Migrations/CreateSearchProjectionTables.php', '--force' => true]);
        }
    }

    public function test_candidate_query_includes_a_limit_clause(): void
    {
        $this->bulkSeedRows(10, 'scope-a');

        $this->captureSearchSelect();

        (new SearchAccessibleRecordsHandler(new SearchBoundAllowDecider))->handle(
            ['user_id' => 'u', 'facility_id' => 'scope-a'],
            '',
            null,
            10,
        );

        $this->assertNotEmpty($this->capturedSearchSelects, 'Expected a SELECT against search_index_entries.');
        $this->assertStringContainsString('limit', strtolower($this->capturedSearchSelects[0]->sql));
    }

    public function test_candidate_query_overfetches_above_requested_limit(): void
    {
        $this->bulkSeedRows(300, 'scope-a');

        $this->captureSearchSelect();

        (new SearchAccessibleRecordsHandler(new SearchBoundAllowDecider))->handle(
            ['user_id' => 'u', 'facility_id' => 'scope-a'],
            '',
            null,
            25,
        );

        $this->assertSame(125, $this->extractLimit($this->capturedSearchSelects[0]->sql));
    }

    public function test_candidate_query_is_capped_at_hard_ceiling(): void
    {
        $this->bulkSeedRows(2000, 'scope-a');

        $this->captureSearchSelect();

        (new SearchAccessibleRecordsHandler(new SearchBoundAllowDecider))->handle(
            ['user_id' => 'u', 'facility_id' => 'scope-a'],
            '',
            null,
            100,
        );

        $this->assertSame(500, $this->extractLimit($this->capturedSearchSelects[0]->sql));
    }

    public function test_total_is_bounded_when_table_has_many_more_rows(): void
    {
        $this->bulkSeedRows(2500, 'scope-a');

        $result = (new SearchAccessibleRecordsHandler(new SearchBoundAllowDecider))->handle(
            ['user_id' => 'u', 'facility_id' => 'scope-a'],
            '',
            null,
            25,
        );

        $this->assertCount(25, $result['items']);
        $this->assertSame(125, $result['total']);
    }

    public function test_denied_rows_are_excluded_from_items_and_total(): void
    {
        $this->bulkSeedRows(20, 'scope-a');
        $this->bulkSeedRows(20, 'scope-b');

        $result = (new SearchAccessibleRecordsHandler(new SearchBoundScopeDecider))->handle(
            ['user_id' => 'u', 'facility_id' => 'scope-a'],
            '',
            null,
            100,
        );

        $this->assertCount(20, $result['items']);
        $this->assertSame(20, $result['total']);
        foreach ($result['items'] as $item) {
            $this->assertSame('scope-a', $item['scope_id']);
        }
    }

    private function captureSearchSelect(): void
    {
        DB::listen(function ($query): void {
            $sql = ltrim($query->sql);
            if (str_contains($sql, 'search_index_entries') && stripos($sql, 'select') === 0) {
                $this->capturedSearchSelects[] = (object) [
                    'sql' => $query->sql,
                    'bindings' => $query->bindings,
                    'time' => $query->time,
                ];
            }
        });
    }

    private function extractLimit(string $sql): int
    {
        $lower = strtolower($sql);
        if (! preg_match('/\blimit\s+(\d+)\b/', $lower, $m)) {
            $this->fail("SQL did not contain a LIMIT clause: {$sql}");
        }

        return (int) $m[1];
    }

    private function bulkSeedRows(int $count, string $scope): void
    {
        $now = now();
        $projectionVersion = IndexSourceEventHandler::PROJECTION_VERSION;
        $batchSize = 250;
        for ($offset = 0; $offset < $count; $offset += $batchSize) {
            $rows = [];
            $end = min($offset + $batchSize, $count);
            for ($i = $offset; $i < $end; $i++) {
                $sourceId = $scope.'-record-'.$i;
                $rows[] = [
                    'id' => $this->deterministicUuid('work_record|'.$sourceId.'|'.$projectionVersion),
                    'source_module' => 'WorkRecords',
                    'source_type' => 'work_record',
                    'source_id' => $sourceId,
                    'source_version' => 'v1',
                    'projection_version' => $projectionVersion,
                    'scope_id' => $scope,
                    'classification' => 'internal',
                    'visibility' => 'eligible',
                    'title' => 'Record '.$i,
                    'excerpt' => null,
                    'search_text' => 'Record '.$i,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('search_index_entries')->insert($rows);
        }
    }

    private function deterministicUuid(string $value): string
    {
        $hex = sha1($value);
        $hex[12] = '5';
        $hex[16] = in_array($hex[16], ['8', '9', 'a', 'b'], true) ? $hex[16] : '8';

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}

final class SearchBoundAllowDecider implements DecideAccess
{
    public function evaluateOnly(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        return $this->decide($actor, $capability, $facts);
    }

    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        return new AccessDecision(
            'allow',
            $capability,
            $facts === null ? 'work_record' : $facts->resourceType,
            [],
            'test',
            'test',
            $facts === null ? 'internal' : $facts->classification,
        );
    }
}

final class SearchBoundScopeDecider implements DecideAccess
{
    public function evaluateOnly(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        return $this->decide($actor, $capability, $facts);
    }

    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        $allowed = $facts !== null
            && ($actor['facility_id'] ?? null) === $facts->ownerFacilityId;

        return new AccessDecision(
            $allowed ? 'allow' : 'deny',
            $capability,
            $facts === null ? 'work_record' : $facts->resourceType,
            [],
            'test',
            'test',
            $facts === null ? 'internal' : $facts->classification,
        );
    }
}
