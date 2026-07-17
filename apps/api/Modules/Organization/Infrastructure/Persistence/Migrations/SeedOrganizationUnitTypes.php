<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TYPES = [
        ['id' => '0197f0e0-0000-7000-8000-000000000201', 'code' => 'sector', 'name_ar' => 'قطاع'],
        ['id' => '0197f0e0-0000-7000-8000-000000000202', 'code' => 'department', 'name_ar' => 'إدارة'],
        ['id' => '0197f0e0-0000-7000-8000-000000000203', 'code' => 'section', 'name_ar' => 'قسم'],
        ['id' => '0197f0e0-0000-7000-8000-000000000204', 'code' => 'unit', 'name_ar' => 'وحدة'],
        ['id' => '0197f0e0-0000-7000-8000-000000000205', 'code' => 'committee', 'name_ar' => 'لجنة'],
    ];

    public function up(): void
    {
        DB::table('unit_types')->insert(array_map(
            static fn (array $type): array => [
                ...$type,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            self::TYPES,
        ));
    }

    public function down(): void
    {
        // The tree schema rollback removes organization_units before unit_types.
    }
};
