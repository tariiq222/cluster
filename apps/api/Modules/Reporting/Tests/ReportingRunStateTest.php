<?php

namespace Modules\Reporting\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Reporting\Features\RefreshReportingProjection\Handler\RefreshReportingProjectionHandler;
use Modules\Reporting\Features\RunAuthorizedReport\Handler\RunAuthorizedReportHandler;
use RuntimeException;
use Tests\TestCase;

final class ReportingRunStateTest extends TestCase
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

    public function test_repeated_gets_reuse_one_cached_run_per_scope_and_never_grow_report_runs(): void
    {
        $handler = new RunAuthorizedReportHandler(new ReportingAllowingDecider);
        $actor = ['user_id' => 'user-1', 'facility_id' => 'scope-a'];

        $first = $handler->handle(self::REPORT, $actor);
        $second = $handler->handle(self::REPORT, $actor);
        $third = $handler->handle(self::REPORT, $actor);

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame($first['id'], $third['id']);
        $this->assertSame(1, DB::table('report_runs')->count());
        $this->assertSame(1, DB::table('report_runs')->where('report_id', self::REPORT)->where('scope_id', 'scope-a')->count());

        $otherScope = $handler->handle(self::REPORT, ['user_id' => 'user-1', 'facility_id' => 'scope-b']);
        $this->assertNotSame($first['id'], $otherScope['id']);
        $this->assertSame(2, DB::table('report_runs')->count());
        $this->assertSame(1, DB::table('report_runs')->where('scope_id', 'scope-b')->count());
    }

    public function test_failed_run_persists_failed_status_and_error_message_and_is_replaced_by_next_success(): void
    {
        $handler = new RunAuthorizedReportHandler(new ReportingThrowingDecider);
        $actor = ['user_id' => 'user-1', 'facility_id' => 'scope-a'];

        try {
            $handler->handle(self::REPORT, $actor);
            $this->fail('Expected the throwing decider to propagate.');
        } catch (RuntimeException) {
            // expected
        }

        $failed = DB::table('report_runs')->where('report_id', self::REPORT)->where('scope_id', 'scope-a')->first();
        $this->assertNotNull($failed);
        $this->assertSame('failed', $failed->status);
        $this->assertNotNull($failed->error_message);
        $this->assertSame(0, (int) $failed->result_count);

        $success = (new RunAuthorizedReportHandler(new ReportingAllowingDecider))->handle(self::REPORT, $actor);
        $this->assertSame((string) $failed->id, $success['id']);
        $this->assertSame('completed', $success['status']);
        $this->assertSame(1, DB::table('report_runs')->count());

        $replaced = DB::table('report_runs')->where('id', $failed->id)->first();
        $this->assertSame('completed', $replaced->status);
        $this->assertNull($replaced->error_message);
        $this->assertSame(1, (int) $replaced->result_count);
    }
}

final class ReportingThrowingDecider implements DecideAccess
{
    public function evaluateOnly(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        return $this->decide($actor, $capability, $facts);
    }

    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        throw new RuntimeException('deliberate authorization failure');
    }
}
