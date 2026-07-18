<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temporary_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('person_id')->constrained('people')->restrictOnDelete();
            $table->foreignUuid('organization_unit_id')->constrained('organization_units')->restrictOnDelete();
            $table->dateTime('start_at', 3);
            $table->dateTime('end_at', 3);
            $table->enum('state', ['pending', 'active', 'expired', 'revoked'])->default('pending');
            $table->text('reason');
            $table->uuid('approved_by_user_id');
            $table->dateTime('revoked_at', 3)->nullable();
            $table->uuid('revoked_by_user_id')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(
                ['person_id', 'organization_unit_id', 'start_at', 'end_at'],
                'temporary_assignments_person_unit_period_index',
            );
            $table->index(['organization_unit_id', 'state'], 'temporary_assignments_unit_state_index');
            $table->index(['state', 'end_at'], 'temporary_assignments_expiration_index');
        });

        Schema::create('temporary_assignment_capabilities', function (Blueprint $table): void {
            $table->uuid('temporary_assignment_id');
            $table->string('capability_code', 96);

            $table->primary(
                ['temporary_assignment_id', 'capability_code'],
                'temporary_assignment_capabilities_primary',
            );
            $table->foreign('temporary_assignment_id', 'temporary_assignment_capabilities_assignment_foreign')
                ->references('id')
                ->on('temporary_assignments')
                ->cascadeOnDelete();
            $table->index('capability_code', 'temporary_assignment_capability_code_index');
        });

        $this->addSupportedDatabaseChecks();
    }

    public function down(): void
    {
        Schema::dropIfExists('temporary_assignment_capabilities');
        Schema::dropIfExists('temporary_assignments');
    }

    private function addSupportedDatabaseChecks(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE temporary_assignments
                ADD CONSTRAINT temporary_assignments_window_check
                CHECK (end_at > start_at AND end_at <= DATE_ADD(start_at, INTERVAL 90 DAY))
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE temporary_assignments
                ADD CONSTRAINT temporary_assignments_reason_check
                CHECK (CHAR_LENGTH(TRIM(reason)) BETWEEN 1 AND 2000)
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE temporary_assignments
                ADD CONSTRAINT temporary_assignments_revocation_check
                CHECK (
                    (
                        state = 'revoked'
                        AND revoked_at IS NOT NULL
                        AND revoked_by_user_id IS NOT NULL
                        AND revocation_reason IS NOT NULL
                        AND CHAR_LENGTH(TRIM(revocation_reason)) BETWEEN 1 AND 2000
                    )
                    OR
                    (
                        state <> 'revoked'
                        AND revoked_at IS NULL
                        AND revoked_by_user_id IS NULL
                        AND revocation_reason IS NULL
                    )
                )
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE temporary_assignments
                ADD CONSTRAINT temporary_assignments_lock_version_check
                CHECK (lock_version >= 1)
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE temporary_assignment_capabilities
                ADD CONSTRAINT temporary_assignment_capability_format_check
                CHECK (
                    CHAR_LENGTH(capability_code) BETWEEN 1 AND 96
                    AND LOCATE('*', capability_code) = 0
                    AND LOCATE('?', capability_code) = 0
                    AND LOCATE('%', capability_code) = 0
                    AND LOCATE('_', capability_code) = 0
                )
                SQL);

            return;
        }

        if ($driver !== 'sqlite') {
            return;
        }

        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $name => $operation) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER temporary_assignments_{$name}_check
                BEFORE {$operation} ON temporary_assignments
                WHEN
                    julianday(NEW.start_at) IS NULL
                    OR julianday(NEW.end_at) IS NULL
                    OR julianday(NEW.end_at) <= julianday(NEW.start_at)
                    OR julianday(NEW.end_at) - julianday(NEW.start_at) > 90
                    OR length(trim(NEW.reason)) NOT BETWEEN 1 AND 2000
                    OR NEW.lock_version < 1
                    OR NEW.state NOT IN ('pending', 'active', 'expired', 'revoked')
                    OR (
                        NEW.state = 'revoked'
                        AND (
                            NEW.revoked_at IS NULL
                            OR NEW.revoked_by_user_id IS NULL
                            OR NEW.revocation_reason IS NULL
                            OR length(trim(NEW.revocation_reason)) NOT BETWEEN 1 AND 2000
                        )
                    )
                    OR (
                        NEW.state <> 'revoked'
                        AND (
                            NEW.revoked_at IS NOT NULL
                            OR NEW.revoked_by_user_id IS NOT NULL
                            OR NEW.revocation_reason IS NOT NULL
                        )
                    )
                BEGIN
                    SELECT RAISE(ABORT, 'temporary_assignments_check');
                END
                SQL);
            DB::unprepared(<<<SQL
                CREATE TRIGGER temporary_assignment_capabilities_{$name}_check
                BEFORE {$operation} ON temporary_assignment_capabilities
                WHEN
                    length(NEW.capability_code) NOT BETWEEN 1 AND 96
                    OR instr(NEW.capability_code, '*') > 0
                    OR instr(NEW.capability_code, '?') > 0
                    OR instr(NEW.capability_code, '%') > 0
                    OR instr(NEW.capability_code, '_') > 0
                BEGIN
                    SELECT RAISE(ABORT, 'temporary_assignment_capability_check');
                END
                SQL);
        }
    }
};
