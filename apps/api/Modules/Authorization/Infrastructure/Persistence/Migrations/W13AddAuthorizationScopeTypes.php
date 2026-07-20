<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const SCOPED_TABLES = ['role_assignments', 'delegations'];

    public function up(): void
    {
        foreach (self::SCOPED_TABLES as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'scope_type')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->string('scope_type', 16)->nullable()->after('scope_id');
                });
            }
        }

        // Fail-closed backfill: rows carrying a scope_id become unit scopes,
        // while legacy global rows (null scope_id) lose their effect because
        // null scope data is no longer a global-scope shortcut. Those rows are
        // preserved with status 'revoked' instead of being deleted.
        foreach (self::SCOPED_TABLES as $tableName) {
            DB::table($tableName)
                ->whereNotNull('scope_id')
                ->whereNull('scope_type')
                ->update(['scope_type' => 'unit']);
            DB::table($tableName)
                ->whereNull('scope_id')
                ->whereNull('scope_type')
                ->update(['scope_type' => 'cluster', 'status' => 'revoked']);
        }

        $this->addSupportedDatabaseChecks();
    }

    public function down(): void
    {
        $this->dropSupportedDatabaseChecks();

        foreach (self::SCOPED_TABLES as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'scope_type')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->dropColumn('scope_type');
                });
            }
        }
    }

    private function addSupportedDatabaseChecks(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            foreach (self::SCOPED_TABLES as $tableName) {
                DB::statement(<<<SQL
                    ALTER TABLE {$tableName}
                    ADD CONSTRAINT {$tableName}_scope_type_check
                    CHECK (scope_type IS NULL OR scope_type IN ('cluster', 'facility', 'unit', 'record_set'))
                    SQL);
            }

            return;
        }

        if ($driver !== 'sqlite') {
            return;
        }

        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $name => $operation) {
            foreach (self::SCOPED_TABLES as $tableName) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$tableName}_scope_type_{$name}_check");
                DB::unprepared(<<<SQL
                    CREATE TRIGGER {$tableName}_scope_type_{$name}_check
                    BEFORE {$operation} ON {$tableName}
                    WHEN NEW.scope_type IS NOT NULL
                        AND NEW.scope_type NOT IN ('cluster', 'facility', 'unit', 'record_set')
                    BEGIN
                        SELECT RAISE(ABORT, '{$tableName}_scope_type_check');
                    END
                    SQL);
            }
        }
    }

    private function dropSupportedDatabaseChecks(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            foreach (self::SCOPED_TABLES as $tableName) {
                DB::statement("ALTER TABLE {$tableName} DROP CHECK {$tableName}_scope_type_check");
            }

            return;
        }

        if ($driver !== 'sqlite') {
            return;
        }

        foreach (['insert', 'update'] as $name) {
            foreach (self::SCOPED_TABLES as $tableName) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$tableName}_scope_type_{$name}_check");
            }
        }
    }
};
