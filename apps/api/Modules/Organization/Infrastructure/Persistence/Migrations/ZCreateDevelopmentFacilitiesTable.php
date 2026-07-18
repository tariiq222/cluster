<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Organization\Infrastructure\Fixtures\DevelopmentFacilityFixtures;

return new class extends Migration
{
    public function up(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        Schema::create('organization_development_facilities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->timestamps();
        });

        DB::table('organization_development_facilities')->insert(array_map(
            static fn (array $facility): array => [
                ...$facility,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            DevelopmentFacilityFixtures::facilities(),
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_development_facilities');
    }
};
