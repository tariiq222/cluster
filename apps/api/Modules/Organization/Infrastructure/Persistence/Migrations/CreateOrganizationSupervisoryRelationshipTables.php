<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supervisory_relationships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('source_organization_unit_id')
                ->constrained('organization_units')
                ->restrictOnDelete();
            $table->foreignUuid('target_organization_unit_id')
                ->constrained('organization_units')
                ->restrictOnDelete();
            $table->string('relationship_type', 16);
            $table->dateTime('valid_from', 3);
            $table->dateTime('valid_until', 3);
            $table->timestamps();

            $table->index(
                ['source_organization_unit_id', 'valid_from', 'valid_until'],
                'supervisory_relationships_source_period_index',
            );
            $table->index(
                ['target_organization_unit_id', 'valid_from', 'valid_until'],
                'supervisory_relationships_target_period_index',
            );
            $table->index('relationship_type', 'supervisory_relationships_type_index');
        });

        Schema::create('relationship_capabilities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('supervisory_relationship_id')
                ->constrained('supervisory_relationships')
                ->cascadeOnDelete();
            $table->string('module_code', 64);
            $table->string('capability_code', 64);

            $table->unique(
                ['supervisory_relationship_id', 'module_code', 'capability_code'],
                'relationship_capabilities_relationship_module_capability_unique',
            );
            $table->index(
                ['module_code', 'capability_code'],
                'relationship_capabilities_module_capability_index',
            );
        });

        $this->addSupportedDatabaseChecks();
    }

    public function down(): void
    {
        Schema::dropIfExists('relationship_capabilities');
        Schema::dropIfExists('supervisory_relationships');
    }

    private function addSupportedDatabaseChecks(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE supervisory_relationships
                ADD CONSTRAINT supervisory_relationships_type_check
                CHECK (relationship_type IN ('direct', 'functional', 'coordination', 'read_only'))
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE supervisory_relationships
                ADD CONSTRAINT supervisory_relationships_period_check
                CHECK (valid_until > valid_from)
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE supervisory_relationships
                ADD CONSTRAINT supervisory_relationships_distinct_units_check
                CHECK (source_organization_unit_id <> target_organization_unit_id)
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE relationship_capabilities
                ADD CONSTRAINT relationship_capabilities_code_check
                CHECK (
                    CHAR_LENGTH(TRIM(module_code)) BETWEEN 1 AND 64
                    AND CHAR_LENGTH(TRIM(capability_code)) BETWEEN 1 AND 64
                )
                SQL);

            return;
        }

        if ($driver !== 'sqlite') {
            return;
        }

        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $name => $operation) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER supervisory_relationships_{$name}_check
                BEFORE {$operation} ON supervisory_relationships
                WHEN
                    NEW.source_organization_unit_id = NEW.target_organization_unit_id
                    OR NEW.relationship_type NOT IN ('direct', 'functional', 'coordination', 'read_only')
                    OR julianday(NEW.valid_from) IS NULL
                    OR julianday(NEW.valid_until) IS NULL
                    OR julianday(NEW.valid_until) <= julianday(NEW.valid_from)
                BEGIN
                    SELECT RAISE(ABORT, 'supervisory_relationships_check');
                END
                SQL);
            DB::unprepared(<<<SQL
                CREATE TRIGGER relationship_capabilities_{$name}_check
                BEFORE {$operation} ON relationship_capabilities
                WHEN
                    length(trim(NEW.module_code)) NOT BETWEEN 1 AND 64
                    OR length(trim(NEW.capability_code)) NOT BETWEEN 1 AND 64
                BEGIN
                    SELECT RAISE(ABORT, 'relationship_capabilities_check');
                END
                SQL);
        }
    }
};
