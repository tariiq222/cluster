<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds a deterministic sibling order to the organization tree and
     * backfills existing rows. Sibling order alone was previously implicit
     * (UUID insertion order), which produced visually scattered boards and
     * non-deterministic line routing in the OrganizationStructure page.
     *
     * The column is denormalised: ordering is a presentation concern, but
     * keeping it on the row lets the cursor-paginated list query return a
     * stable, top-to-bottom order without an extra round trip.
     */
    public function up(): void
    {
        Schema::table('organization_units', function (Blueprint $table): void {
            $table->unsignedInteger('sort_order')->default(0)->after('code');
            $table->index(['parent_type', 'parent_id', 'sort_order'], 'organization_units_sibling_order_index');
        });

        $typePriority = [
            'sector' => 1,
            'department' => 2,
            'section' => 3,
            'unit' => 4,
            'committee' => 5,
        ];

        $rows = DB::table('organization_units as ou')
            ->join('unit_types as ut', 'ut.id', '=', 'ou.unit_type_id')
            ->select('ou.id', 'ou.parent_type', 'ou.parent_id', 'ut.code as type_code', 'ou.code')
            ->orderBy('ou.parent_type')
            ->orderBy('ou.parent_id')
            ->orderByRaw('CASE ut.code WHEN ? THEN 1 WHEN ? THEN 2 WHEN ? THEN 3 WHEN ? THEN 4 WHEN ? THEN 5 ELSE 99 END', [
                'sector',
                'department',
                'section',
                'unit',
                'committee',
            ])
            ->orderBy('ou.code')
            ->orderBy('ou.id')
            ->get();

        $nextByParent = [];
        foreach ($rows as $row) {
            $parentKey = $row->parent_type.'/'.$row->parent_id;
            $nextByParent[$parentKey] = ($nextByParent[$parentKey] ?? 0) + 1;
            DB::table('organization_units')
                ->where('id', $row->id)
                ->update(['sort_order' => $nextByParent[$parentKey]]);
        }
    }

    public function down(): void
    {
        Schema::table('organization_units', function (Blueprint $table): void {
            $table->dropIndex('organization_units_sibling_order_index');
            $table->dropColumn('sort_order');
        });
    }
};
