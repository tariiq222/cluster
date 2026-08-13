<?php

namespace Tests\Architecture;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Modules\Authorization\Contracts\CapabilityCatalog;
use Tests\TestCase;

final class TaskCoreScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_retired_work_management_modules_are_absent(): void
    {
        foreach (['WorkRecords', 'WorkDefinitions', 'Workflow'] as $module) {
            $this->assertDirectoryDoesNotExist(base_path('Modules/'.$module));
        }
    }

    public function test_runtime_has_no_work_management_feature_flag(): void
    {
        $this->assertArrayNotHasKey('work_management', config('features'));
    }

    public function test_runtime_registers_no_retired_work_management_routes(): void
    {
        $retiredPrefixes = ['work-records', 'work-definitions', 'work-definition-versions', 'workflow'];
        $uris = array_map(
            static fn ($route): string => $route->uri(),
            Route::getRoutes()->getRoutes(),
        );

        foreach ($retiredPrefixes as $prefix) {
            $this->assertSame(
                [],
                array_values(array_filter($uris, static fn (string $uri): bool => str_starts_with($uri, 'api/v1/'.$prefix))),
                'Retired route prefix remains registered: '.$prefix,
            );
        }
    }

    public function test_capability_catalog_exposes_only_retained_product_capabilities(): void
    {
        $retiredPrefixes = ['work_record.', 'work_definition.', 'workflow.'];

        foreach (CapabilityCatalog::all() as $capability) {
            $this->assertNotSame('work_management.history.read', $capability);
            foreach ($retiredPrefixes as $prefix) {
                $this->assertFalse(
                    str_starts_with($capability, $prefix),
                    'Retired capability remains registered: '.$capability,
                );
            }
        }
    }

    public function test_migration_registry_excludes_retired_modules(): void
    {
        $paths = array_map(static fn (string $path): string => str_replace('\\', '/', $path), config('module_migrations'));

        foreach (['/Modules/WorkRecords/', '/Modules/WorkDefinitions/', '/Modules/Workflow/'] as $fragment) {
            $this->assertSame(
                [],
                array_values(array_filter($paths, static fn (string $path): bool => str_contains($path, $fragment))),
                'Retired migration path remains registered: '.$fragment,
            );
        }
    }

    public function test_task_schema_has_no_generic_source_or_workflow_columns(): void
    {
        foreach (['workflow_step_id', 'source_module', 'source_type', 'source_id'] as $column) {
            $this->assertFalse(Schema::hasColumn('tasks', $column), 'Legacy task column remains: '.$column);
        }
    }

    public function test_retirement_migration_removes_legacy_tables_capabilities_and_role(): void
    {
        Schema::create('work_records', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
        });
        DB::table('roles')->insert([
            'id' => '01980f50-5f0d-7000-8000-000000000990',
            'code' => 'operations_office_member',
            'name_ar' => 'عضو مكتب إدارة العمليات',
            'name_en' => 'Operations office member',
            'role_type' => 'system',
            'status' => 'active',
            'is_system_role' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('capabilities')->insert([
            'id' => '01980f50-5f0d-7000-8000-000000000991',
            'module_code' => 'workflow',
            'capability_code' => 'workflow.read',
            'action' => 'read',
            'sensitivity' => 'normal',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require base_path('Shared/Infrastructure/Migrations/W27RetireWorkManagement.php');
        $migration->up();

        $this->assertFalse(Schema::hasTable('work_records'));
        $this->assertDatabaseMissing('roles', ['code' => 'operations_office_member']);
        $this->assertDatabaseMissing('capabilities', ['capability_code' => 'workflow.read']);
    }

    public function test_task_cleanup_migration_removes_every_legacy_link_column(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->uuid('workflow_step_id')->nullable()->unique();
            $table->string('source_module')->nullable();
            $table->string('source_type')->nullable();
            $table->uuid('source_id')->nullable();
        });

        $migration = require base_path('Modules/Tasks/Infrastructure/Persistence/Migrations/W27RemoveWorkManagementLinks.php');
        $migration->up();

        foreach (['workflow_step_id', 'source_module', 'source_type', 'source_id'] as $column) {
            $this->assertFalse(Schema::hasColumn('tasks', $column));
        }
    }
}
