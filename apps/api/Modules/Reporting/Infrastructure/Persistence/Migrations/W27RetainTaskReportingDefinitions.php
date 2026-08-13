<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REPORT_ID = '019f7000-0000-7000-8000-000000000901';

    private const DASHBOARD_ID = '019f7000-0000-7000-8000-000000000902';

    public function up(): void
    {
        if (! Schema::hasTable('report_definitions') || ! Schema::hasTable('dashboard_definitions')) {
            return;
        }

        DB::transaction(function (): void {
            $this->assertControlledIdsAreSafe();
            $this->removeRetiredProjectionData();
            $reportId = $this->retainTaskReport();
            $this->retainTaskDashboard($reportId);
        });
    }

    public function down(): void
    {
        // Forward-only correction: the retired report definition is not restored.
    }

    private function assertControlledIdsAreSafe(): void
    {
        $reportCode = DB::table('report_definitions')->where('id', self::REPORT_ID)->value('code');
        $dashboardCode = DB::table('dashboard_definitions')->where('id', self::DASHBOARD_ID)->value('code');

        if (($reportCode !== null && ! in_array((string) $reportCode, ['r1-work-records', 'tasks-overview'], true))
            || ($dashboardCode !== null && ! in_array((string) $dashboardCode, ['r1-work-records', 'tasks-overview'], true))) {
            throw new RuntimeException('reporting_w27_controlled_seed_id_collision');
        }
    }

    private function removeRetiredProjectionData(): void
    {
        if (Schema::hasTable('report_read_models')) {
            DB::table('report_read_models')
                ->where(static function ($query): void {
                    $query->whereIn('source_module', [
                        'WorkRecords', 'work-records', 'work_records', 'work_record',
                        'WorkDefinitions', 'work-definitions', 'work_definitions', 'work_definition',
                        'Workflow', 'workflow',
                    ])->orWhereIn('source_type', [
                        'work_record', 'work_definition', 'workflow_definition', 'workflow_instance', 'workflow_step',
                    ]);
                })
                ->delete();
        }

        if (! Schema::hasTable('report_runs')
            || DB::table('report_definitions')->where('id', self::REPORT_ID)->value('code') !== 'r1-work-records') {
            return;
        }

        $runIds = DB::table('report_runs')->where('report_id', self::REPORT_ID)->pluck('id');
        if (Schema::hasTable('export_artifacts')) {
            DB::table('export_artifacts')->whereIn('report_run_id', $runIds)->delete();
        }
        DB::table('report_runs')->whereIn('id', $runIds)->delete();
    }

    private function retainTaskReport(): string
    {
        $existingTarget = DB::table('report_definitions')->where('code', 'tasks-overview')->first();
        $seed = DB::table('report_definitions')->where('id', self::REPORT_ID)->first();

        if ($existingTarget !== null && (string) $existingTarget->id !== self::REPORT_ID) {
            if ($seed !== null && (string) $seed->code === 'r1-work-records') {
                DB::table('dashboard_definitions')->where('report_id', self::REPORT_ID)->delete();
                DB::table('report_definitions')->where('id', self::REPORT_ID)->delete();
            }

            return (string) $existingTarget->id;
        }

        $values = [
            'code' => 'tasks-overview',
            'title' => 'ملخص مهام نطاق المنشأة',
            'status' => 'published',
            'projection_version' => 'w1.9-v1',
            'updated_at' => now(),
        ];
        if ($seed === null) {
            DB::table('report_definitions')->insert([
                'id' => self::REPORT_ID,
                ...$values,
                'created_at' => now(),
            ]);
        } elseif (in_array((string) $seed->code, ['r1-work-records', 'tasks-overview'], true)) {
            DB::table('report_definitions')->where('id', self::REPORT_ID)->update($values);
        }

        return self::REPORT_ID;
    }

    private function retainTaskDashboard(string $reportId): void
    {
        $existingTarget = DB::table('dashboard_definitions')->where('code', 'tasks-overview')->first();
        $seed = DB::table('dashboard_definitions')->where('id', self::DASHBOARD_ID)->first();
        if ($existingTarget !== null && (string) $existingTarget->id !== self::DASHBOARD_ID) {
            if ($seed !== null && (string) $seed->code === 'r1-work-records') {
                DB::table('dashboard_definitions')->where('id', self::DASHBOARD_ID)->delete();
            }

            return;
        }

        $values = [
            'code' => 'tasks-overview',
            'title' => 'لوحة مهام المنشأة',
            'report_id' => $reportId,
            'status' => 'published',
            'updated_at' => now(),
        ];
        if ($seed === null) {
            DB::table('dashboard_definitions')->insert([
                'id' => self::DASHBOARD_ID,
                ...$values,
                'created_at' => now(),
            ]);
        } elseif (in_array((string) $seed->code, ['r1-work-records', 'tasks-overview'], true)) {
            DB::table('dashboard_definitions')->where('id', self::DASHBOARD_ID)->update($values);
        }
    }
};
