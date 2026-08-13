<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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

    public function up(): void
    {
        $this->assertDestructiveMigrationIsAuthorized();

        DB::transaction(function (): void {
            $this->removeRetiredAuthorizationData();
            $this->removeRetiredDerivedData();
        });

        foreach (self::RETIRED_TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        // This retirement is intentionally irreversible. Restore retired data
        // from a verified deployment backup if a rollback is required.
    }

    private function removeRetiredAuthorizationData(): void
    {
        if (Schema::hasTable('roles')) {
            $retiredRoleIds = DB::table('roles')
                ->where('code', 'operations_office_member')
                ->pluck('id');
            if (Schema::hasTable('role_assignments')) {
                DB::table('role_assignments')->whereIn('role_id', $retiredRoleIds)->delete();
            }
            if (Schema::hasTable('role_capabilities')) {
                DB::table('role_capabilities')->whereIn('role_id', $retiredRoleIds)->delete();
            }
            DB::table('roles')->whereIn('id', $retiredRoleIds)->delete();
        }

        $matchesRetiredCapability = static function ($query): void {
            $query->whereRaw('HEX(SUBSTR(capability_code, 1, ?)) = HEX(?)', [strlen('work_record.'), 'work_record.'])
                ->orWhereRaw('HEX(SUBSTR(capability_code, 1, ?)) = HEX(?)', [strlen('work_definition.'), 'work_definition.'])
                ->orWhereRaw('HEX(SUBSTR(capability_code, 1, ?)) = HEX(?)', [strlen('workflow.'), 'workflow.'])
                ->orWhereRaw('HEX(capability_code) = HEX(?)', ['work_management.history.read']);
        };

        if (Schema::hasTable('role_capabilities') && Schema::hasTable('capabilities')) {
            $retiredIds = DB::table('capabilities')->where($matchesRetiredCapability)->pluck('id');
            DB::table('role_capabilities')->whereIn('capability_id', $retiredIds)->delete();
        }
        foreach (['delegation_capabilities', 'explicit_denies'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->where($matchesRetiredCapability)->delete();
            }
        }
        if (Schema::hasTable('capabilities')) {
            DB::table('capabilities')->where($matchesRetiredCapability)->delete();
        }
        if (Schema::hasTable('field_access_templates')) {
            DB::table('field_access_templates')
                ->whereIn('module_code', ['work_record', 'work_records', 'work_definition', 'workflow'])
                ->delete();
        }
        if (Schema::hasTable('classification_policies')) {
            DB::table('classification_policies')
                ->where('minimum_capability', 'work_record.read')
                ->update(['minimum_capability' => 'tasks.read', 'updated_at' => now()]);
        }
    }

    private function removeRetiredDerivedData(): void
    {
        $matchesRetiredSource = static function ($query): void {
            $query->whereIn('source_module', [
                'WorkRecords', 'work-records', 'work_records', 'work_record',
                'WorkDefinitions', 'work-definitions', 'work_definitions', 'work_definition',
                'Workflow', 'workflow',
            ])->orWhereIn('source_type', [
                'work_record', 'work_definition', 'workflow_definition', 'workflow_instance', 'workflow_step',
            ]);
        };

        if (Schema::hasTable('search_index_entries')) {
            DB::table('search_index_entries')->where($matchesRetiredSource)->delete();
        }
        if (Schema::hasTable('search_checkpoints')) {
            DB::table('search_checkpoints')
                ->whereIn('consumer', ['work_records.backfill', 'work-records.backfill'])
                ->delete();
        }
        if (Schema::hasTable('report_read_models')) {
            DB::table('report_read_models')->where($matchesRetiredSource)->delete();
        }
        if (Schema::hasTable('document_links')) {
            DB::table('document_links')
                ->where('status', 'active')
                ->where(static function ($query): void {
                    $query->whereIn('source_module', ['WorkRecords', 'work-records', 'work_records', 'work_record'])
                        ->orWhere('source_type', 'work_record');
                })
                ->update([
                    'status' => 'unlinked',
                    'unlinked_at' => now(),
                    'unlink_reason' => 'source_module_retired',
                    'updated_at' => now(),
                ]);
        }
    }

    private function assertDestructiveMigrationIsAuthorized(): void
    {
        $hasRetiredTable = false;
        foreach (self::RETIRED_TABLES as $table) {
            if (Schema::hasTable($table)) {
                $hasRetiredTable = true;
                break;
            }
        }

        if (! $hasRetiredTable || config('app.env') !== 'production') {
            return;
        }

        foreach ([
            config('features.destructive_migrations.backup_id'),
            config('features.destructive_migrations.restore_validation_id'),
        ] as $value) {
            if (! is_string($value) || ! $this->isEvidenceIdentifier($value)) {
                throw new RuntimeException('work_management_retirement_requires_verified_backup_and_restore');
            }
        }
    }

    private function isEvidenceIdentifier(string $value): bool
    {
        $value = trim($value);
        if (strlen($value) < 8 || preg_match('/[<>${}]/', $value) === 1) {
            return false;
        }

        return preg_match('/^(?:change-?me|replace-?me|placeholder|todo|tbd|none|null|unknown|example)$/i', $value) !== 1;
    }
};
