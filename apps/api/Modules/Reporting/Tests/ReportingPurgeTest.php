<?php

namespace Modules\Reporting\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Reporting\Features\PurgeExpiredReporting\Handler\PurgeExpiredReportingHandler;
use Modules\Reporting\Features\RefreshReportingProjection\Handler\RefreshReportingProjectionHandler;
use Modules\Reporting\Features\RunAuthorizedReport\Handler\RunAuthorizedReportHandler;
use Tests\TestCase;

final class ReportingPurgeTest extends TestCase
{
    use RefreshDatabase;

    private const REPORT = '0197f0e0-0000-7000-8000-000000000010';

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('report_read_models')) {
            $this->artisan('migrate', ['--path' => 'Modules/Reporting/Infrastructure/Persistence/Migrations/CreateReportingProjectionTables.php', '--force' => true]);
        }
        if (! Schema::hasColumn('report_runs', 'error_message')) {
            $this->artisan('migrate', ['--path' => 'Modules/Reporting/Infrastructure/Persistence/Migrations/ZAddReportRunFailureState.php', '--force' => true]);
        }
        (new RefreshReportingProjectionHandler)->handle([
            'report_id' => self::REPORT,
            'source_module' => 'Tasks',
            'source_type' => 'task',
            'source_id' => 'record-1',
            'source_version' => 'v1',
            'scope_id' => 'scope-a',
            'title' => 'Visible',
            'safe_data' => ['status' => 'open'],
        ]);
    }

    public function test_purge_deletes_expired_artifacts_and_orphaned_runs_in_batches_but_keeps_live_artifacts(): void
    {
        $now = now();
        $this->artifact('artifact-expired', 'run-expired', 'expired-artifact-1', $now->copy()->subDay());
        $this->artifact('artifact-live', 'run-live', 'live-artifact-1', $now->copy()->addDay());

        $result = (new PurgeExpiredReportingHandler)->purge(100);

        $this->assertSame(1, $result['artifacts_purged']);
        $this->assertSame(1, $result['runs_purged']);
        $this->assertFalse($result['has_more']);
        $this->assertNull(DB::table('export_artifacts')->where('id', 'artifact-expired')->first());
        $this->assertNotNull(DB::table('export_artifacts')->where('id', 'artifact-live')->first());
        $this->assertNull(DB::table('report_runs')->where('id', 'run-expired')->first());
        $this->assertNotNull(DB::table('report_runs')->where('id', 'run-live')->first());
    }

    public function test_purge_is_bounded_by_limit_and_reports_more_due(): void
    {
        $now = now();
        for ($i = 0; $i < 5; $i++) {
            $this->artifact('artifact-'.$i, 'run-'.$i, 'payload-'.$i, $now->copy()->subDay());
        }

        $first = (new PurgeExpiredReportingHandler)->purge(2);
        $this->assertSame(2, $first['artifacts_purged']);
        $this->assertTrue($first['has_more']);

        $rest = (new PurgeExpiredReportingHandler)->purge(100);
        $this->assertSame(3, $rest['artifacts_purged']);
        $this->assertFalse($rest['has_more']);
        $this->assertSame(0, DB::table('export_artifacts')->count());
    }

    public function test_purge_keeps_runs_referenced_by_live_artifacts_and_stale_get_cache_runs_are_removed(): void
    {
        $now = now();
        $this->artifact('artifact-live', 'run-live', 'live', $now->copy()->addDay());

        DB::table('report_runs')->insert([
            'id' => 'run-stale-cache',
            'report_id' => self::REPORT,
            'actor_id' => 'user-1',
            'scope_id' => 'scope-a',
            'status' => 'completed',
            'result_count' => 0,
            'result' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => $now->copy()->subDays(3),
            'updated_at' => $now->copy()->subDays(3),
        ]);

        $result = (new PurgeExpiredReportingHandler)->purge(100);

        $this->assertSame(0, $result['artifacts_purged']);
        $this->assertSame(1, $result['runs_purged']);
        $this->assertNotNull(DB::table('report_runs')->where('id', 'run-live')->first());
        $this->assertNull(DB::table('report_runs')->where('id', 'run-stale-cache')->first());
    }

    public function test_purge_command_requires_once_and_reports_purged_counts(): void
    {
        $this->artisan('reporting:purge-expired')->assertExitCode(1);

        $this->artifact('artifact-expired', 'run-expired', 'expired', now()->subDay());

        $this->artisan('reporting:purge-expired', ['--once' => true])
            ->expectsOutputToContain('Purged 1 expired artifact(s) and 1 orphaned run(s)')
            ->assertExitCode(0);
        $this->assertSame(0, DB::table('export_artifacts')->count());
    }

    public function test_repeated_get_recreates_a_purged_cache_run_self_healing(): void
    {
        (new RunAuthorizedReportHandler(new ReportingAllowingDecider))->handle(self::REPORT, ['user_id' => 'user-1', 'facility_id' => 'scope-a']);

        DB::table('report_runs')->update(['updated_at' => now()->subDays(3)]);
        (new PurgeExpiredReportingHandler)->purge(100);
        $this->assertSame(0, DB::table('report_runs')->count());

        $run = (new RunAuthorizedReportHandler(new ReportingAllowingDecider))->handle(self::REPORT, ['user_id' => 'user-1', 'facility_id' => 'scope-a']);
        $this->assertSame('completed', $run['status']);
        $this->assertSame(1, DB::table('report_runs')->count());
    }

    private function artifact(string $artifactId, string $runId, string $payload, Carbon $expiresAt): void
    {
        $now = now();
        DB::table('report_runs')->insert([
            'id' => $runId,
            'report_id' => self::REPORT,
            'actor_id' => 'user-1',
            'scope_id' => 'scope-a',
            'status' => 'completed',
            'result_count' => 1,
            'result' => json_encode([['id' => 'row-1', 'source_id' => $payload]], JSON_THROW_ON_ERROR),
            'created_at' => $now->copy()->subDays(2),
            'updated_at' => $now->copy()->subDays(2),
        ]);
        DB::table('export_artifacts')->insert([
            'id' => $artifactId,
            'report_run_id' => $runId,
            'format' => 'csv',
            'status' => 'available',
            'result_count' => 1,
            'safe_result' => 'id,source_id'."\r\n".'row-1,'.$payload."\r\n",
            'expires_at' => $expiresAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
