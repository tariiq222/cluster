<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('explicit_denies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->string('capability_code', 96);
            $table->string('classification', 16)->nullable();
            $table->uuid('organization_unit_id')->nullable();
            $table->string('resource_pattern', 96)->nullable();
            $table->text('reason');
            $table->uuid('issued_by_user_id');
            $table->dateTime('issued_at', 3);
            $table->dateTime('expires_at', 3)->nullable();
            $table->boolean('revocable');
            $table->timestamps(3);

            $table->index(
                ['user_id', 'capability_code', 'issued_at', 'expires_at'],
                'explicit_denies_user_active_index',
            );
            $table->index(
                ['organization_unit_id', 'capability_code', 'issued_at', 'expires_at'],
                'explicit_denies_scope_active_index',
            );
        });

        $this->addSupportedDatabaseChecks();
    }

    public function down(): void
    {
        Schema::dropIfExists('explicit_denies');
    }

    private function addSupportedDatabaseChecks(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE explicit_denies
                ADD CONSTRAINT explicit_denies_target_check
                CHECK (user_id IS NOT NULL OR organization_unit_id IS NOT NULL)
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE explicit_denies
                ADD CONSTRAINT explicit_denies_window_check
                CHECK (expires_at IS NULL OR expires_at > issued_at)
                SQL);

            return;
        }

        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $name => $operation) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER explicit_denies_{$name}_check
                BEFORE {$operation} ON explicit_denies
                WHEN (NEW.user_id IS NULL AND NEW.organization_unit_id IS NULL)
                    OR julianday(NEW.issued_at) IS NULL
                    OR (NEW.expires_at IS NOT NULL AND julianday(NEW.expires_at) <= julianday(NEW.issued_at))
                BEGIN
                    SELECT RAISE(ABORT, 'explicit_denies_target_or_window_check');
                END
                SQL);
        }
    }
};
