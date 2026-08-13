<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class WorkManagementRetirementMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_retirement_matches_capability_prefixes_literally_and_cleans_derived_data_before_dropping_sources(): void
    {
        $this->createLegacyWorkRecordsTable();
        $this->seedCapabilityFixtures();
        $this->seedDerivedDataFixtures();

        $this->retirementMigration()->up();

        $this->assertFalse(Schema::hasTable('work_records'));
        foreach (['work_record.read', 'work_definition.manage', 'workflow.read'] as $retired) {
            $this->assertDatabaseMissing('capabilities', ['capability_code' => $retired]);
        }
        foreach (['workXrecord.read', 'workXdefinition.manage', 'WORK_RECORD.read', 'tasks.read'] as $retained) {
            $this->assertDatabaseHas('capabilities', ['capability_code' => $retained]);
        }
        $this->assertDatabaseMissing('delegation_capabilities', ['capability_code' => 'work_record.read']);
        $this->assertDatabaseHas('delegation_capabilities', ['capability_code' => 'workXrecord.read']);
        $this->assertDatabaseMissing('explicit_denies', ['capability_code' => 'workflow.read']);
        $this->assertDatabaseHas('explicit_denies', ['capability_code' => 'tasks.read']);

        $this->assertDatabaseMissing('search_index_entries', ['source_id' => 'legacy-work-record']);
        $this->assertDatabaseHas('search_index_entries', ['source_id' => 'retained-task']);
        $this->assertDatabaseMissing('search_checkpoints', ['consumer' => 'work_records.backfill']);
        $this->assertDatabaseMissing('report_read_models', ['source_id' => 'legacy-work-record']);
        $this->assertDatabaseHas('report_read_models', ['source_id' => 'retained-task']);
        $this->assertDatabaseHas('document_links', [
            'id' => '018f6f7d-0c00-7000-8000-000000000831',
            'status' => 'unlinked',
            'unlink_reason' => 'source_module_retired',
        ]);
        $this->assertDatabaseHas('document_links', [
            'id' => '018f6f7d-0c00-7000-8000-000000000832',
            'status' => 'active',
        ]);
    }

    public function test_production_retirement_fails_closed_before_any_change_without_backup_and_restore_evidence(): void
    {
        $this->createLegacyWorkRecordsTable();
        DB::table('work_records')->insert(['id' => '018f6f7d-0c00-7000-8000-000000000840']);
        DB::table('capabilities')->insert($this->capabilityRow(
            '018f6f7d-0c00-7000-8000-000000000841',
            'work_record.read',
        ));

        $originalEnvironment = config('app.env');
        $originalBackup = getenv('DESTRUCTIVE_MIGRATION_BACKUP_ID');
        $originalRestore = getenv('DESTRUCTIVE_MIGRATION_RESTORE_VALIDATION_ID');
        config([
            'app.env' => 'production',
            'features.destructive_migrations.backup_id' => 'replace-me',
            'features.destructive_migrations.restore_validation_id' => '',
        ]);
        putenv('DESTRUCTIVE_MIGRATION_BACKUP_ID=backup-env-only-valid-123');
        putenv('DESTRUCTIVE_MIGRATION_RESTORE_VALIDATION_ID=restore-env-only-valid-123');

        try {
            $this->retirementMigration()->up();
            $this->fail('Production retirement must require verified backup and restore evidence.');
        } catch (RuntimeException $exception) {
            $this->assertSame('work_management_retirement_requires_verified_backup_and_restore', $exception->getMessage());
        } finally {
            config(['app.env' => $originalEnvironment]);
            $this->restoreEnvironment('DESTRUCTIVE_MIGRATION_BACKUP_ID', $originalBackup);
            $this->restoreEnvironment('DESTRUCTIVE_MIGRATION_RESTORE_VALIDATION_ID', $originalRestore);
        }

        $this->assertTrue(Schema::hasTable('work_records'));
        $this->assertDatabaseHas('work_records', ['id' => '018f6f7d-0c00-7000-8000-000000000840']);
        $this->assertDatabaseHas('capabilities', ['capability_code' => 'work_record.read']);
    }

    public function test_copied_production_example_w27_evidence_fails_closed_before_any_change(): void
    {
        $this->createLegacyWorkRecordsTable();
        DB::table('work_records')->insert(['id' => '018f6f7d-0c00-7000-8000-000000000842']);
        DB::table('capabilities')->insert($this->capabilityRow(
            '018f6f7d-0c00-7000-8000-000000000843',
            'work_record.read',
        ));

        $originalEnvironment = config('app.env');
        config([
            'app.env' => 'production',
            'features.destructive_migrations.backup_id' => $this->productionExampleValue('DESTRUCTIVE_MIGRATION_BACKUP_ID'),
            'features.destructive_migrations.restore_validation_id' => $this->productionExampleValue('DESTRUCTIVE_MIGRATION_RESTORE_VALIDATION_ID'),
        ]);

        try {
            $this->retirementMigration()->up();
            $this->fail('Copied production example evidence must not authorize the W27 retirement.');
        } catch (RuntimeException $exception) {
            $this->assertSame('work_management_retirement_requires_verified_backup_and_restore', $exception->getMessage());
        } finally {
            config(['app.env' => $originalEnvironment]);
        }

        $this->assertTrue(Schema::hasTable('work_records'));
        $this->assertDatabaseHas('work_records', ['id' => '018f6f7d-0c00-7000-8000-000000000842']);
        $this->assertDatabaseHas('capabilities', ['capability_code' => 'work_record.read']);
    }

    public function test_fresh_production_install_does_not_require_destructive_migration_evidence(): void
    {
        $originalEnvironment = config('app.env');
        config([
            'app.env' => 'production',
            'features.destructive_migrations.backup_id' => null,
            'features.destructive_migrations.restore_validation_id' => null,
        ]);

        try {
            $this->retirementMigration()->up();
            $this->addToAssertionCount(1);
        } finally {
            config(['app.env' => $originalEnvironment]);
        }
    }

    public function test_production_retirement_uses_cached_configuration_evidence_instead_of_runtime_environment(): void
    {
        $this->createLegacyWorkRecordsTable();
        $originalEnvironment = config('app.env');
        $originalBackup = getenv('DESTRUCTIVE_MIGRATION_BACKUP_ID');
        $originalRestore = getenv('DESTRUCTIVE_MIGRATION_RESTORE_VALIDATION_ID');
        config([
            'app.env' => 'production',
            'features.destructive_migrations.backup_id' => 'backup-config-valid-123',
            'features.destructive_migrations.restore_validation_id' => 'restore-config-valid-123',
        ]);
        putenv('DESTRUCTIVE_MIGRATION_BACKUP_ID=replace-me');
        putenv('DESTRUCTIVE_MIGRATION_RESTORE_VALIDATION_ID=replace-me');

        try {
            $this->retirementMigration()->up();
        } finally {
            config(['app.env' => $originalEnvironment]);
            $this->restoreEnvironment('DESTRUCTIVE_MIGRATION_BACKUP_ID', $originalBackup);
            $this->restoreEnvironment('DESTRUCTIVE_MIGRATION_RESTORE_VALIDATION_ID', $originalRestore);
        }

        $this->assertFalse(Schema::hasTable('work_records'));
    }

    public function test_forward_job_title_repair_fixes_an_already_migrated_arabic_collision(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('positions');
        Schema::dropIfExists('job_titles');
        Schema::enableForeignKeyConstraints();
        Schema::create('positions', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title_ar');
            $table->timestamps();
        });
        DB::table('positions')->insert([
            ['id' => '018f6f7d-0c00-7000-8000-000000000851', 'title_ar' => 'مدير', 'created_at' => now(), 'updated_at' => now()],
            ['id' => '018f6f7d-0c00-7000-8000-000000000852', 'title_ar' => 'طبيب', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $historical = require base_path('Modules/Organization/Infrastructure/Persistence/Migrations/W2AddOrganizationJobTitlesTable.php');
        $historical->up();
        $this->assertSame(1, DB::table('job_titles')->count(), 'The fixture must reproduce historical W2 data loss.');
        $this->assertSame(1, DB::table('positions')->whereNotNull('job_title_id')->count());

        $repair = require base_path('Modules/Organization/Infrastructure/Persistence/Migrations/W27RepairOrganizationJobTitles.php');
        $repair->up();

        $this->assertSame(2, DB::table('job_titles')->count());
        $this->assertSame(2, DB::table('positions')->whereNotNull('job_title_id')->count());
        $codes = DB::table('job_titles')->orderBy('title_ar')->pluck('code')->all();
        $this->assertCount(2, array_unique($codes));
        foreach ($codes as $code) {
            $this->assertLessThanOrEqual(64, strlen((string) $code));
        }
    }

    public function test_forward_job_title_repair_detects_collisions_after_the_final_64_character_truncation(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('positions');
        Schema::dropIfExists('job_titles');
        Schema::enableForeignKeyConstraints();
        Schema::create('positions', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title_ar');
            $table->timestamps();
        });
        $prefix = str_repeat('A', 64);
        DB::table('positions')->insert([
            ['id' => '018f6f7d-0c00-7000-8000-000000000853', 'title_ar' => $prefix.'-ONE', 'created_at' => now(), 'updated_at' => now()],
            ['id' => '018f6f7d-0c00-7000-8000-000000000854', 'title_ar' => $prefix.'-TWO', 'created_at' => now(), 'updated_at' => now()],
        ]);

        (require base_path('Modules/Organization/Infrastructure/Persistence/Migrations/W2AddOrganizationJobTitlesTable.php'))->up();
        $this->assertSame(1, DB::table('job_titles')->count(), 'Historical W2 truncation must collide in this fixture.');

        $repair = require base_path('Modules/Organization/Infrastructure/Persistence/Migrations/W27RepairOrganizationJobTitles.php');
        $repair->up();
        $firstCodes = DB::table('job_titles')->orderBy('title_ar')->pluck('code')->all();
        $repair->up();
        $secondCodes = DB::table('job_titles')->orderBy('title_ar')->pluck('code')->all();

        $this->assertCount(2, $firstCodes);
        $this->assertSame($firstCodes, $secondCodes, 'The repair must be deterministic and retry-safe.');
        $this->assertCount(2, array_unique(array_map('strtoupper', $firstCodes)));
        foreach ($firstCodes as $code) {
            $this->assertLessThanOrEqual(64, strlen((string) $code));
        }
        $this->assertSame(2, DB::table('positions')->whereNotNull('job_title_id')->count());
    }

    public function test_task_cleanup_retries_after_the_unique_index_was_already_removed(): void
    {
        Schema::dropIfExists('task_idempotency_keys');
        Schema::dropIfExists('tasks');
        Schema::create('tasks', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workflow_step_id')->nullable();
            $table->string('source_module')->nullable();
            $table->string('source_type')->nullable();
            $table->string('source_id')->nullable();
        });

        $migration = require base_path('Modules/Tasks/Infrastructure/Persistence/Migrations/W27RemoveWorkManagementLinks.php');
        $migration->up();
        $migration->up();

        foreach (['workflow_step_id', 'source_module', 'source_type', 'source_id'] as $column) {
            $this->assertFalse(Schema::hasColumn('tasks', $column));
        }
    }

    public function test_historical_reporting_seed_is_immutable_and_forward_migration_retargets_it(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['report_inbox', 'export_artifacts', 'report_runs', 'report_read_models', 'dashboard_definitions', 'report_definitions'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();

        $historical = require base_path('Modules/Reporting/Infrastructure/Persistence/Migrations/CreateReportingProjectionTables.php');
        $historical->up();
        $this->assertDatabaseHas('report_definitions', [
            'id' => '019f7000-0000-7000-8000-000000000901',
            'code' => 'r1-work-records',
        ]);

        $corrective = require base_path('Modules/Reporting/Infrastructure/Persistence/Migrations/W27RetainTaskReportingDefinitions.php');
        $corrective->up();

        $this->assertDatabaseHas('report_definitions', [
            'id' => '019f7000-0000-7000-8000-000000000901',
            'code' => 'tasks-overview',
            'title' => 'ملخص مهام نطاق المنشأة',
        ]);
        $this->assertDatabaseHas('dashboard_definitions', [
            'id' => '019f7000-0000-7000-8000-000000000902',
            'code' => 'tasks-overview',
            'title' => 'لوحة مهام المنشأة',
        ]);

        DB::table('report_runs')->insert([
            'id' => '018f6f7d-0c00-7000-8000-000000000870',
            'report_id' => '019f7000-0000-7000-8000-000000000901',
            'actor_id' => null,
            'scope_id' => null,
            'status' => 'completed',
            'result_count' => 0,
            'result' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $corrective->up();

        $this->assertDatabaseHas('report_runs', ['id' => '018f6f7d-0c00-7000-8000-000000000870']);
    }

    public function test_reporting_correction_refuses_to_overwrite_an_unrelated_definition_using_the_controlled_id(): void
    {
        DB::table('report_definitions')
            ->where('id', '019f7000-0000-7000-8000-000000000901')
            ->update(['code' => 'unrelated-report', 'title' => 'تقرير مستقل']);
        DB::table('dashboard_definitions')
            ->where('id', '019f7000-0000-7000-8000-000000000902')
            ->update(['code' => 'unrelated-dashboard', 'title' => 'لوحة مستقلة']);

        $corrective = require base_path('Modules/Reporting/Infrastructure/Persistence/Migrations/W27RetainTaskReportingDefinitions.php');
        try {
            $corrective->up();
            $this->fail('The corrective migration must not hijack unrelated rows using controlled IDs.');
        } catch (RuntimeException $exception) {
            $this->assertSame('reporting_w27_controlled_seed_id_collision', $exception->getMessage());
        }

        $this->assertDatabaseHas('report_definitions', [
            'id' => '019f7000-0000-7000-8000-000000000901',
            'code' => 'unrelated-report',
            'title' => 'تقرير مستقل',
        ]);
        $this->assertDatabaseHas('dashboard_definitions', [
            'id' => '019f7000-0000-7000-8000-000000000902',
            'code' => 'unrelated-dashboard',
            'title' => 'لوحة مستقلة',
        ]);
    }

    private function createLegacyWorkRecordsTable(): void
    {
        Schema::create('work_records', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
        });
    }

    private function seedCapabilityFixtures(): void
    {
        $codes = [
            'work_record.read',
            'work_definition.manage',
            'workflow.read',
            'workXrecord.read',
            'workXdefinition.manage',
            'WORK_RECORD.read',
            'tasks.read',
        ];
        $fixtureCapabilityIds = DB::table('capabilities')->whereIn('capability_code', $codes)->pluck('id');
        DB::table('role_capabilities')->whereIn('capability_id', $fixtureCapabilityIds)->delete();
        DB::table('capabilities')->whereIn('id', $fixtureCapabilityIds)->delete();

        $rows = [];
        foreach ($codes as $index => $code) {
            $rows[] = $this->capabilityRow(
                sprintf('018f6f7d-0c00-7000-8000-%012d', 810 + $index),
                $code,
            );
        }
        DB::table('capabilities')->insert($rows);

        DB::table('delegations')->insert([
            'id' => '018f6f7d-0c00-7000-8000-000000000820',
            'delegator_user_id' => '018f6f7d-0c00-7000-8000-000000000821',
            'delegate_user_id' => '018f6f7d-0c00-7000-8000-000000000822',
            'module_code' => 'test',
            'scope_id' => null,
            'start_at' => now(),
            'end_at' => now()->addDay(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('delegation_capabilities')->insert([
            ['delegation_id' => '018f6f7d-0c00-7000-8000-000000000820', 'capability_code' => 'work_record.read'],
            ['delegation_id' => '018f6f7d-0c00-7000-8000-000000000820', 'capability_code' => 'workXrecord.read'],
        ]);
        foreach ([['workflow.read', '823'], ['tasks.read', '824']] as [$code, $suffix]) {
            DB::table('explicit_denies')->insert([
                'id' => '018f6f7d-0c00-7000-8000-000000000'.$suffix,
                'user_id' => '018f6f7d-0c00-7000-8000-000000000825',
                'capability_code' => $code,
                'classification' => null,
                'organization_unit_id' => null,
                'resource_pattern' => null,
                'reason' => 'fixture',
                'issued_by_user_id' => '018f6f7d-0c00-7000-8000-000000000826',
                'issued_at' => now(),
                'expires_at' => null,
                'revocable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedDerivedDataFixtures(): void
    {
        foreach ([
            ['018f6f7d-0c00-7000-8000-000000000827', 'WorkRecords', 'work_record', 'legacy-work-record'],
            ['018f6f7d-0c00-7000-8000-000000000828', 'Tasks', 'task', 'retained-task'],
        ] as [$id, $module, $type, $sourceId]) {
            DB::table('search_index_entries')->insert([
                'id' => $id,
                'source_module' => $module,
                'source_type' => $type,
                'source_id' => $sourceId,
                'source_version' => 'v1',
                'projection_version' => 'v1',
                'scope_id' => null,
                'classification' => 'internal',
                'visibility' => 'eligible',
                'status' => 'active',
                'title' => $sourceId,
                'excerpt' => null,
                'search_text' => $sourceId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('report_read_models')->insert([
                'id' => str_replace('00000000082', '00000000083', $id),
                'report_id' => '019f7000-0000-7000-8000-000000000901',
                'source_module' => $module,
                'source_type' => $type,
                'source_id' => $sourceId,
                'source_version' => 'v1',
                'scope_id' => null,
                'classification' => 'internal',
                'projection_version' => 'v1',
                'title' => $sourceId,
                'safe_data' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('search_checkpoints')->insert([
            'consumer' => 'work_records.backfill',
            'checkpoint' => 'legacy-work-record',
            'projection_version' => 'v1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([
            ['018f6f7d-0c00-7000-8000-000000000831', 'work-records', 'work_record', 'legacy-work-record'],
            ['018f6f7d-0c00-7000-8000-000000000832', 'tasks', 'task', 'retained-task'],
        ] as [$id, $module, $type, $sourceId]) {
            DB::table('document_links')->insert([
                'id' => $id,
                'document_id' => '018f6f7d-0c00-7000-8000-000000000833',
                'source_module' => $module,
                'source_type' => $type,
                'source_id' => $sourceId,
                'relation_type' => 'attachment',
                'constraint_policy_key' => null,
                'link_classification' => 'internal',
                'linked_by_user_id' => '018f6f7d-0c00-7000-8000-000000000834',
                'status' => 'active',
                'unlinked_at' => null,
                'unlink_reason' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function capabilityRow(string $id, string $code): array
    {
        return [
            'id' => $id,
            'module_code' => str_contains($code, '.') ? explode('.', $code, 2)[0] : 'test',
            'capability_code' => $code,
            'action' => 'read',
            'sensitivity' => 'normal',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function retirementMigration(): object
    {
        return require base_path('Shared/Infrastructure/Migrations/W27RetireWorkManagement.php');
    }

    private function productionExampleValue(string $variable): string
    {
        $contents = file_get_contents(base_path('../../infra/platform/production/.env.example'));
        $this->assertNotFalse($contents);

        foreach (explode("\n", $contents) as $line) {
            if (str_starts_with($line, $variable.'=')) {
                return substr($line, strlen($variable) + 1);
            }
        }

        $this->fail(sprintf('Production example is missing %s.', $variable));
    }

    private function restoreEnvironment(string $name, string|false $value): void
    {
        if ($value === false) {
            putenv($name);

            return;
        }

        putenv($name.'='.$value);
    }
}
