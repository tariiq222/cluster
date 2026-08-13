<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class WorkManagementRetirementMySqlUpgradeTest extends TestCase
{
    use DatabaseTruncation;

    /** @var list<string> */
    private const FORWARD_MIGRATIONS = [
        'W27RepairOrganizationJobTitles',
        'W27RetireWorkManagement',
        'W27RemoveWorkManagementLinks',
        'W27RetainTaskReportingDefinitions',
    ];

    /** @var list<string> */
    private const RETIRED_TABLES = [
        'workflow_decisions',
        'workflow_step_instances',
        'workflow_instances',
        'workflow_idempotency_keys',
        'workflow_versions',
        'workflow_definitions',
        'work_record_idempotency_keys',
        'work_records',
        'work_definition_development_work_type_versions',
        'work_definition_idempotency_keys',
        'work_definition_versions',
        'work_definitions',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the explicit MySQL integration lane.');
        }
    }

    public function test_registered_migrator_upgrades_a_truthful_pre_w27_database_without_touching_protected_rows(): void
    {
        $this->restorePreW27MigrationLedger();
        $this->createLegacyOwnedTablesAndData();
        $this->restoreLegacyTaskColumnsAndData();
        $this->restoreLegacyReportingSeedAndDerivedData();
        $this->seedRetiredAndProtectedAuthorizationData();

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        foreach (self::FORWARD_MIGRATIONS as $migration) {
            $this->assertDatabaseHas('migrations', ['migration' => $migration]);
        }
        foreach (self::RETIRED_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), 'Retired table remains after normal migrator: '.$table);
        }
        foreach (['workflow_step_id', 'source_module', 'source_type', 'source_id'] as $column) {
            $this->assertFalse(Schema::hasColumn('tasks', $column));
        }
        $this->assertDatabaseHas('tasks', ['id' => '018f6f7d-0c00-7000-8000-000000000861']);

        $this->assertDatabaseMissing('capabilities', ['capability_code' => 'workflow.read']);
        foreach (['workXrecord.read', 'WORK_RECORD.read', 'tasks.read'] as $protected) {
            $this->assertDatabaseHas('capabilities', ['capability_code' => $protected]);
        }
        $this->assertDatabaseHas('delegation_capabilities', ['capability_code' => 'WORK_RECORD.read']);
        $this->assertDatabaseHas('explicit_denies', ['capability_code' => 'workXrecord.read']);

        $this->assertDatabaseMissing('search_index_entries', ['source_id' => 'legacy-work-record']);
        $this->assertDatabaseHas('search_index_entries', ['source_id' => 'retained-task']);
        $this->assertDatabaseMissing('report_read_models', ['source_id' => 'legacy-work-record']);
        $this->assertDatabaseHas('report_read_models', ['source_id' => 'retained-task']);
        $this->assertDatabaseHas('document_links', [
            'id' => '018f6f7d-0c00-7000-8000-000000000871',
            'status' => 'unlinked',
            'unlink_reason' => 'source_module_retired',
        ]);
        $this->assertDatabaseHas('document_links', [
            'id' => '018f6f7d-0c00-7000-8000-000000000872',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('report_definitions', [
            'id' => '019f7000-0000-7000-8000-000000000901',
            'code' => 'tasks-overview',
        ]);

        $this->assertSame(2, DB::table('job_titles')->whereIn('title_ar', ['مدير', 'طبيب'])->count());
        $this->assertSame(2, DB::table('positions')->whereIn('title_ar', ['مدير', 'طبيب'])->whereNotNull('job_title_id')->count());

        DB::table('migrations')->whereIn('migration', self::FORWARD_MIGRATIONS)->delete();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertDatabaseHas('tasks', ['id' => '018f6f7d-0c00-7000-8000-000000000861']);
        $this->assertDatabaseHas('capabilities', ['capability_code' => 'WORK_RECORD.read']);
    }

    private function restorePreW27MigrationLedger(): void
    {
        DB::table('migrations')->whereIn('migration', self::FORWARD_MIGRATIONS)->delete();
        $batch = ((int) DB::table('migrations')->max('batch')) + 1;
        foreach ([
            'CreateDevelopmentWorkTypeFixturesTable',
            'CreateWorkDefinitionTables',
            'CreateWorkRecordsTable',
            'W13AddWorkRecordFieldPolicyKey',
            'W26AddWorkRecordIdempotencyResponsePayload',
            'CreateWorkflowTables',
            'W14AddWorkflowStepAssignee',
            'W16CreateWorkflowDecisionsTable',
            'W17AddApprovalColumnsToWorkflowVersions',
            'W22AddWorkflowDecisionStepUnique',
            'W23AddWorkflowInstanceSourceUnique',
        ] as $migration) {
            DB::table('migrations')->insertOrIgnore(['migration' => $migration, 'batch' => $batch]);
        }
    }

    private function createLegacyOwnedTablesAndData(): void
    {
        $tables = [
            'work_definitions',
            'work_definition_versions',
            'work_definition_idempotency_keys',
            'work_definition_development_work_type_versions',
            'work_records',
            'work_record_idempotency_keys',
            'workflow_definitions',
            'workflow_versions',
            'workflow_idempotency_keys',
            'workflow_instances',
            'workflow_step_instances',
            'workflow_decisions',
        ];
        foreach ($tables as $index => $table) {
            Schema::create($table, static function (Blueprint $blueprint): void {
                $blueprint->uuid('id')->primary();
                $blueprint->string('legacy_value')->nullable();
            });
            DB::table($table)->insert([
                'id' => sprintf('018f6f7d-0c00-7000-8000-%012d', 880 + $index),
                'legacy_value' => 'pre-w27-fixture',
            ]);
        }
    }

    private function restoreLegacyTaskColumnsAndData(): void
    {
        $this->seedFixtureOrganization();
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->string('source_module', 64)->nullable();
            $table->string('source_type', 128)->nullable();
            $table->string('source_id', 128)->nullable();
            $table->uuid('workflow_step_id')->nullable()->unique();
        });
        DB::table('tasks')->insert([
            'id' => '018f6f7d-0c00-7000-8000-000000000861',
            'title' => 'Retained task',
            'description' => null,
            'created_by_user_id' => '018f6f7d-0c00-7000-8000-000000000862',
            'assignee_user_id' => '018f6f7d-0c00-7000-8000-000000000863',
            'owner_organization_unit_id' => null,
            'status' => 'open',
            'due_at' => null,
            'priority' => 'normal',
            'classification' => 'internal',
            'completion_policy' => 'direct',
            'source_module' => 'workflow',
            'source_type' => 'workflow_step',
            'source_id' => 'legacy-workflow-step',
            'workflow_step_id' => '018f6f7d-0c00-7000-8000-000000000864',
            'lock_version' => 1,
            'completed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $positionIds = DB::table('positions')->whereIn('title_ar', ['مدير', 'طبيب'])->pluck('id');
        DB::table('positions')->whereIn('id', $positionIds)->delete();
        DB::table('job_titles')->whereIn('title_ar', ['مدير', 'طبيب'])->delete();
        $unitId = DB::table('organization_units')->value('id');
        if (! is_string($unitId)) {
            throw new \RuntimeException('mysql_upgrade_fixture_requires_organization_unit');
        }
        DB::table('job_titles')->insert([
            'id' => $this->jobTitleId('مدير'),
            'code' => 'TITLE',
            'title_ar' => 'مدير',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('positions')->insert([
            [
                'id' => '018f6f7d-0c00-7000-8000-000000000865',
                'organization_unit_id' => $unitId,
                'code' => 'W27-AR-ONE',
                'title_ar' => 'مدير',
                'job_title_id' => $this->jobTitleId('مدير'),
                'manager_position_id' => null,
                'is_active' => true,
                'lock_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => '018f6f7d-0c00-7000-8000-000000000866',
                'organization_unit_id' => $unitId,
                'code' => 'W27-AR-TWO',
                'title_ar' => 'طبيب',
                'job_title_id' => null,
                'manager_position_id' => null,
                'is_active' => true,
                'lock_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    private function restoreLegacyReportingSeedAndDerivedData(): void
    {
        DB::table('report_definitions')->where('id', '019f7000-0000-7000-8000-000000000901')->update([
            'code' => 'r1-work-records',
            'title' => 'طلبات نطاق المنشأة',
        ]);
        DB::table('dashboard_definitions')->where('id', '019f7000-0000-7000-8000-000000000902')->update([
            'code' => 'r1-work-records',
            'title' => 'لوحة طلبات المنشأة',
        ]);

        foreach ([
            ['018f6f7d-0c00-7000-8000-000000000867', 'WorkRecords', 'work_record', 'legacy-work-record'],
            ['018f6f7d-0c00-7000-8000-000000000868', 'Tasks', 'task', 'retained-task'],
        ] as [$id, $module, $type, $sourceId]) {
            DB::table('search_index_entries')->insert([
                'id' => $id,
                'source_module' => $module,
                'source_type' => $type,
                'source_id' => $sourceId,
                'source_version' => 'v1',
                'status' => 'active',
                'projection_version' => 'v1',
                'scope_id' => null,
                'classification' => 'internal',
                'visibility' => 'eligible',
                'title' => $sourceId,
                'excerpt' => null,
                'search_text' => $sourceId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('report_read_models')->insert([
                'id' => str_replace('00000000086', '00000000089', $id),
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
            ['018f6f7d-0c00-7000-8000-000000000871', 'work-records', 'work_record', 'legacy-work-record'],
            ['018f6f7d-0c00-7000-8000-000000000872', 'tasks', 'task', 'retained-task'],
        ] as [$id, $module, $type, $sourceId]) {
            DB::table('document_links')->insert([
                'id' => $id,
                'document_id' => '018f6f7d-0c00-7000-8000-000000000873',
                'source_module' => $module,
                'source_type' => $type,
                'source_id' => $sourceId,
                'relation_type' => 'attachment',
                'constraint_policy_key' => null,
                'link_classification' => 'internal',
                'linked_by_user_id' => '018f6f7d-0c00-7000-8000-000000000874',
                'status' => 'active',
                'unlinked_at' => null,
                'unlink_reason' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedRetiredAndProtectedAuthorizationData(): void
    {
        $codes = ['workflow.read', 'workXrecord.read', 'WORK_RECORD.read', 'tasks.read'];
        $ids = DB::table('capabilities')->whereIn('capability_code', $codes)->pluck('id');
        DB::table('role_capabilities')->whereIn('capability_id', $ids)->delete();
        DB::table('capabilities')->whereIn('id', $ids)->delete();
        foreach ($codes as $index => $code) {
            DB::table('capabilities')->insert([
                'id' => sprintf('018f6f7d-0c00-7000-8000-%012d', 900 + $index),
                'module_code' => explode('.', $code, 2)[0],
                'capability_code' => $code,
                'action' => 'read',
                'sensitivity' => 'normal',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('delegations')->insert([
            'id' => '018f6f7d-0c00-7000-8000-000000000904',
            'delegator_user_id' => '018f6f7d-0c00-7000-8000-000000000905',
            'delegate_user_id' => '018f6f7d-0c00-7000-8000-000000000906',
            'module_code' => 'test',
            'scope_id' => null,
            'start_at' => now(),
            'end_at' => now()->addDay(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('delegation_capabilities')->insert([
            ['delegation_id' => '018f6f7d-0c00-7000-8000-000000000904', 'capability_code' => 'workflow.read'],
            ['delegation_id' => '018f6f7d-0c00-7000-8000-000000000904', 'capability_code' => 'WORK_RECORD.read'],
        ]);
        DB::table('explicit_denies')->insert([
            'id' => '018f6f7d-0c00-7000-8000-000000000907',
            'user_id' => '018f6f7d-0c00-7000-8000-000000000908',
            'capability_code' => 'workXrecord.read',
            'classification' => null,
            'organization_unit_id' => null,
            'resource_pattern' => null,
            'reason' => 'protected negative fixture',
            'issued_by_user_id' => '018f6f7d-0c00-7000-8000-000000000909',
            'issued_at' => now(),
            'expires_at' => null,
            'revocable' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function jobTitleId(string $title): string
    {
        $hash = hash('sha256', 'job-title:'.$title);
        $bytes = hex2bin(substr($hash, 0, 32));
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
