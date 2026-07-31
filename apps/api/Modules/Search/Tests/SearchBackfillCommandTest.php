<?php

namespace Modules\Search\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Search\Features\BackfillSearchProjection\Handler\BackfillSearchProjectionHandler;
use Modules\Search\Features\IndexSourceEvent\Handler\IndexSourceEventHandler;
use Tests\TestCase;

final class SearchBackfillCommandTest extends TestCase
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

    public function test_backfill_indexes_work_records_and_resets_the_checkpoint_on_scan_completion(): void
    {
        $this->seedWorkRecord('record-a', 'scope-a', 'First request', 'First description');
        $this->seedWorkRecord('record-b', 'scope-b', 'Second request', 'Second description');

        $this->artisan('search:backfill --once --limit=10')
            ->expectsOutputToContain('Indexed 2 work record(s)')
            ->assertSuccessful();

        $rows = DB::table('search_index_entries')->orderBy('source_id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['record-a', 'record-b'], $rows->pluck('source_id')->all());
        $this->assertSame('First request', $rows->first()->title);
        $this->assertSame('First description', $rows->first()->excerpt);
        $this->assertSame('1', $rows->first()->source_version);
        $this->assertSame('work-records', $rows->first()->source_module);

        $checkpoint = DB::table('search_checkpoints')->where('consumer', BackfillSearchProjectionHandler::CHECKPOINT_CONSUMER)->first();
        $this->assertNotNull($checkpoint);
        $this->assertNull($checkpoint->checkpoint, 'A completed scan must reset the checkpoint so the next run reconciles again.');
        $this->assertSame(IndexSourceEventHandler::PROJECTION_VERSION, $checkpoint->projection_version);
    }

    public function test_backfill_checkpoints_a_full_batch_and_resumes_after_it(): void
    {
        $this->seedWorkRecord('record-a', 'scope-a', 'First request', null);
        $this->seedWorkRecord('record-b', 'scope-a', 'Second request', null);
        $this->seedWorkRecord('record-c', 'scope-a', 'Third request', null);

        $this->artisan('search:backfill --once --limit=2')->assertSuccessful();
        $this->assertSame(2, DB::table('search_index_entries')->count());
        $checkpoint = DB::table('search_checkpoints')->where('consumer', BackfillSearchProjectionHandler::CHECKPOINT_CONSUMER)->value('checkpoint');
        $this->assertSame('record-b', $checkpoint, 'A full batch must checkpoint the last processed record id.');

        $this->artisan('search:backfill --once --limit=2')->assertSuccessful();
        $this->assertSame(3, DB::table('search_index_entries')->count());
        $this->assertSame(
            ['record-a', 'record-b', 'record-c'],
            DB::table('search_index_entries')->orderBy('source_id')->pluck('source_id')->all(),
        );
    }

    public function test_backfill_is_idempotent(): void
    {
        $this->seedWorkRecord('record-a', 'scope-a', 'First request', null);
        $this->artisan('search:backfill --once --limit=10')->assertSuccessful();

        $this->artisan('search:backfill --once --limit=10')->assertSuccessful();

        $this->assertSame(1, DB::table('search_index_entries')->count());
        $this->assertSame(
            ['record-a'],
            DB::table('search_index_entries')->orderBy('source_id')->pluck('source_id')->all(),
        );
    }

    public function test_backfill_reconciles_updated_lock_versions_on_rescan(): void
    {
        $this->seedWorkRecord('record-a', 'scope-a', 'Original title', null, 1);
        $this->artisan('search:backfill --once --limit=10')->assertSuccessful();
        $this->assertSame(1, DB::table('search_index_entries')->count());
        $this->assertSame('Original title', DB::table('search_index_entries')->value('title'));

        DB::table('work_records')->where('id', 'record-a')->update([
            'lock_version' => 2,
            'payload' => json_encode(['title' => 'Updated title', 'description' => 'Updated description'], JSON_THROW_ON_ERROR),
        ]);
        $this->artisan('search:backfill --once --limit=10')->assertSuccessful();

        $this->assertSame(1, DB::table('search_index_entries')->count());
        $row = DB::table('search_index_entries')->first();
        $this->assertSame('Updated title', $row->title);
        $this->assertSame('2', $row->source_version);
    }

    public function test_backfill_repairs_a_wiped_projection(): void
    {
        $this->seedWorkRecord('record-a', 'scope-a', 'First request', null);
        $this->seedWorkRecord('record-b', 'scope-a', 'Second request', null);
        $this->artisan('search:backfill --once --limit=10')->assertSuccessful();
        $this->assertSame(2, DB::table('search_index_entries')->count());

        DB::table('search_index_entries')->delete();
        $this->artisan('search:backfill --once --limit=10')->assertSuccessful();

        $this->assertSame(2, DB::table('search_index_entries')->count());
        $this->assertSame(
            ['record-a', 'record-b'],
            DB::table('search_index_entries')->orderBy('source_id')->pluck('source_id')->all(),
        );
    }

    public function test_backfill_requires_the_bounded_once_mode(): void
    {
        $this->seedWorkRecord('record-a', 'scope-a', 'First request', null);

        $this->artisan('search:backfill --limit=10')->assertFailed();

        $this->assertSame(0, DB::table('search_index_entries')->count());
        $this->assertSame(0, DB::table('search_checkpoints')->count());
    }

    public function test_backfill_rejects_an_out_of_range_limit(): void
    {
        $this->seedWorkRecord('record-a', 'scope-a', 'First request', null);

        $this->artisan('search:backfill --once --limit=0')->assertFailed();
        $this->artisan('search:backfill --once --limit=101')->assertFailed();
        $this->artisan('search:backfill --once --limit=abc')->assertFailed();

        $this->assertSame(0, DB::table('search_index_entries')->count());
    }

    private function seedWorkRecord(string $id, string $scope, string $title, ?string $description, int $lockVersion = 1): string
    {
        DB::table('work_records')->insert([
            'id' => $id,
            'record_number' => 'WR-'.strtoupper(substr(hash('sha256', $id), 0, 12)),
            'work_type_version_id' => '0197f0e0-0000-7000-8000-000000000001',
            'owner_facility_id' => $scope,
            'creator_user_id' => 'user-1',
            'status' => 'submitted',
            'classification' => 'internal',
            'payload' => json_encode(['title' => $title, 'description' => $description], JSON_THROW_ON_ERROR),
            'lock_version' => $lockVersion,
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
