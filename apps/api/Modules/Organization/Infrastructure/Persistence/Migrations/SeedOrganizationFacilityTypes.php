<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TYPES = [
        ['id' => '0197f0e0-0000-7000-8000-000000000101', 'code' => 'hospital', 'name_ar' => 'مستشفى'],
        ['id' => '0197f0e0-0000-7000-8000-000000000102', 'code' => 'center', 'name_ar' => 'مركز صحي'],
        ['id' => '0197f0e0-0000-7000-8000-000000000103', 'code' => 'lab', 'name_ar' => 'مختبر'],
        ['id' => '0197f0e0-0000-7000-8000-000000000104', 'code' => 'shared_services', 'name_ar' => 'خدمات مشتركة'],
    ];

    public function up(): void
    {
        DB::table('facility_types')->insert(array_map(
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
        // The following schema rollback drops facilities before facility_types.
        // Deleting controlled types here would violate existing facility FKs.
    }
};
