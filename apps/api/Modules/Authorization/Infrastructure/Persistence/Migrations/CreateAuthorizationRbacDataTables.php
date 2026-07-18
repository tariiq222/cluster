<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 96)->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('role_type', 32);
            $table->string('status', 16)->default('active')->index();
            $table->boolean('is_system_role')->default(false);
            $table->timestamps(3);

            $table->index(['status', 'role_type'], 'roles_status_type_index');
        });

        Schema::create('capabilities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('module_code', 64);
            $table->string('capability_code', 96);
            $table->string('action', 32);
            $table->string('sensitivity', 16)->default('normal');
            $table->string('status', 16)->default('active');
            $table->timestamps(3);

            $table->unique(['module_code', 'capability_code'], 'capabilities_module_code_unique');
            $table->index(['module_code', 'action', 'status'], 'capabilities_module_action_status_index');
            $table->index(['sensitivity', 'status'], 'capabilities_sensitivity_status_index');
        });

        Schema::create('role_capabilities', function (Blueprint $table): void {
            $table->uuid('role_id');
            $table->uuid('capability_id');
            $table->string('effect', 8)->default('allow');
            $table->dateTime('created_at', 3);

            $table->primary(['role_id', 'capability_id'], 'role_capabilities_primary');
            $table->foreign('role_id', 'role_capabilities_role_foreign')
                ->references('id')
                ->on('roles')
                ->cascadeOnDelete();
            $table->foreign('capability_id', 'role_capabilities_capability_foreign')
                ->references('id')
                ->on('capabilities')
                ->restrictOnDelete();
        });

        Schema::create('role_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('role_id');
            $table->uuid('scope_id')->nullable();
            $table->dateTime('start_at', 3);
            $table->dateTime('end_at', 3)->nullable();
            $table->string('status', 16)->default('pending');
            $table->uuid('granted_by_user_id');
            $table->timestamps(3);

            $table->foreign('role_id', 'role_assignments_role_foreign')
                ->references('id')
                ->on('roles')
                ->restrictOnDelete();
            $table->index(['user_id', 'status', 'start_at', 'end_at'], 'role_assignments_user_period_index');
            $table->index(['scope_id', 'status'], 'role_assignments_scope_status_index');
            $table->index(['role_id', 'status'], 'role_assignments_role_status_index');
        });

        Schema::create('delegations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('delegator_user_id');
            $table->uuid('delegate_user_id');
            $table->string('module_code', 64);
            $table->uuid('scope_id')->nullable();
            $table->dateTime('start_at', 3);
            $table->dateTime('end_at', 3);
            $table->string('status', 16)->default('pending');
            $table->timestamps(3);

            $table->index(['delegate_user_id', 'status', 'start_at', 'end_at'], 'delegations_delegate_period_index');
            $table->index(['delegator_user_id', 'status'], 'delegations_delegator_status_index');
            $table->index(['scope_id', 'status'], 'delegations_scope_status_index');
        });

        Schema::create('delegation_capabilities', function (Blueprint $table): void {
            $table->uuid('delegation_id');
            $table->string('capability_code', 96);

            $table->primary(['delegation_id', 'capability_code'], 'delegation_capabilities_primary');
            $table->foreign('delegation_id', 'delegation_capabilities_delegation_foreign')
                ->references('id')
                ->on('delegations')
                ->cascadeOnDelete();
            $table->index('capability_code', 'delegation_capabilities_code_index');
        });

        $this->addSupportedDatabaseChecks();
    }

    public function down(): void
    {
        Schema::dropIfExists('delegation_capabilities');
        Schema::dropIfExists('delegations');
        Schema::dropIfExists('role_assignments');
        Schema::dropIfExists('role_capabilities');
        Schema::dropIfExists('capabilities');
        Schema::dropIfExists('roles');
    }

    private function addSupportedDatabaseChecks(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE role_assignments
                ADD CONSTRAINT role_assignments_window_check
                CHECK (end_at IS NULL OR end_at > start_at)
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE delegations
                ADD CONSTRAINT delegations_actor_window_check
                CHECK (delegator_user_id <> delegate_user_id AND end_at > start_at)
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE delegation_capabilities
                ADD CONSTRAINT delegation_capabilities_code_check
                CHECK (
                    CHAR_LENGTH(TRIM(capability_code)) BETWEEN 1 AND 96
                    AND LOCATE('*', capability_code) = 0
                    AND LOCATE('?', capability_code) = 0
                    AND LOCATE('%', capability_code) = 0
                )
                SQL);

            return;
        }

        if ($driver !== 'sqlite') {
            return;
        }

        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $name => $operation) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER role_assignments_{$name}_check
                BEFORE {$operation} ON role_assignments
                WHEN julianday(NEW.start_at) IS NULL
                    OR (NEW.end_at IS NOT NULL AND julianday(NEW.end_at) <= julianday(NEW.start_at))
                BEGIN
                    SELECT RAISE(ABORT, 'role_assignments_window_check');
                END
                SQL);
            DB::unprepared(<<<SQL
                CREATE TRIGGER delegations_{$name}_check
                BEFORE {$operation} ON delegations
                WHEN NEW.delegator_user_id = NEW.delegate_user_id
                    OR julianday(NEW.start_at) IS NULL
                    OR julianday(NEW.end_at) IS NULL
                    OR julianday(NEW.end_at) <= julianday(NEW.start_at)
                BEGIN
                    SELECT RAISE(ABORT, 'delegations_actor_window_check');
                END
                SQL);
            DB::unprepared(<<<SQL
                CREATE TRIGGER delegation_capabilities_{$name}_check
                BEFORE {$operation} ON delegation_capabilities
                WHEN length(trim(NEW.capability_code)) NOT BETWEEN 1 AND 96
                    OR instr(NEW.capability_code, '*') > 0
                    OR instr(NEW.capability_code, '?') > 0
                    OR instr(NEW.capability_code, '%') > 0
                BEGIN
                    SELECT RAISE(ABORT, 'delegation_capabilities_code_check');
                END
                SQL);
        }
    }
};
