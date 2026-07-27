<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SENSITIVE_EVENT_INDEX = 'sensitive_access_events_idem_unique';

    public function up(): void
    {
        $this->addSensitiveAccessEventUniqueIndex();
        $this->addAccessDecisionAppendOnlyGuards();
        $this->replaceRoleAssignmentOverlapGuards();
        $this->upgradeFieldAuditTimestampPrecision();
    }

    public function down(): void
    {
        $this->dropAccessDecisionAppendOnlyGuards();
        $this->restoreRoleAssignmentOverlapGuards();
        $this->dropSensitiveAccessEventUniqueIndex();
        $this->restoreFieldAuditTimestampPrecision();
    }

    private function addSensitiveAccessEventUniqueIndex(): void
    {
        if (! Schema::hasTable('sensitive_access_events') || $this->hasIndex('sensitive_access_events', self::SENSITIVE_EVENT_INDEX)) {
            return;
        }

        Schema::table('sensitive_access_events', static function (Blueprint $table): void {
            $table->unique(
                ['idempotency_key_hash', 'resource_type', 'resource_id', 'action'],
                self::SENSITIVE_EVENT_INDEX,
            );
        });
    }

    private function dropSensitiveAccessEventUniqueIndex(): void
    {
        if (! Schema::hasTable('sensitive_access_events') || ! $this->hasIndex('sensitive_access_events', self::SENSITIVE_EVENT_INDEX)) {
            return;
        }

        Schema::table('sensitive_access_events', static function (Blueprint $table): void {
            $table->dropUnique(self::SENSITIVE_EVENT_INDEX);
        });
    }

    private function addAccessDecisionAppendOnlyGuards(): void
    {
        if (! Schema::hasTable('access_decisions')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            foreach (['update' => 'UPDATE', 'delete' => 'DELETE'] as $name => $operation) {
                DB::unprepared("DROP TRIGGER IF EXISTS access_decisions_{$name}_prevent");
                DB::unprepared(<<<SQL
                    CREATE TRIGGER access_decisions_{$name}_prevent
                    BEFORE {$operation} ON access_decisions
                    FOR EACH ROW
                    BEGIN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'access_decisions_append_only';
                    END
                    SQL);
            }

            return;
        }

        if ($driver !== 'sqlite') {
            return;
        }

        foreach (['update' => 'UPDATE', 'delete' => 'DELETE'] as $name => $operation) {
            DB::unprepared("DROP TRIGGER IF EXISTS access_decisions_{$name}_prevent");
            DB::unprepared(<<<SQL
                CREATE TRIGGER access_decisions_{$name}_prevent
                BEFORE {$operation} ON access_decisions
                BEGIN
                    SELECT RAISE(ABORT, 'access_decisions_append_only');
                END
                SQL);
        }
    }

    private function dropAccessDecisionAppendOnlyGuards(): void
    {
        if (! Schema::hasTable('access_decisions')) {
            return;
        }

        foreach (['update', 'delete'] as $name) {
            DB::unprepared("DROP TRIGGER IF EXISTS access_decisions_{$name}_prevent");
        }
    }

    private function replaceRoleAssignmentOverlapGuards(): void
    {
        if (! Schema::hasTable('role_assignments') || ! Schema::hasColumn('role_assignments', 'scope_type')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'sqlite'], true)) {
            return;
        }

        foreach (['insert', 'update'] as $name) {
            DB::unprepared("DROP TRIGGER IF EXISTS role_assignments_{$name}_active_overlap_check");
        }

        if ($driver === 'mysql') {
            $this->addMySqlRoleAssignmentOverlapTriggers();

            return;
        }

        $this->addSqliteRoleAssignmentOverlapTriggers();
    }

    private function restoreRoleAssignmentOverlapGuards(): void
    {
        if (! Schema::hasTable('role_assignments')) {
            return;
        }

        foreach (['insert', 'update'] as $name) {
            DB::unprepared("DROP TRIGGER IF EXISTS role_assignments_{$name}_active_overlap_check");
        }

        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $name => $operation) {
                DB::unprepared(<<<SQL
                    CREATE TRIGGER role_assignments_{$name}_active_overlap_check
                    BEFORE {$operation} ON role_assignments
                    FOR EACH ROW
                    BEGIN
                        IF NEW.status = 'active' AND EXISTS (
                            SELECT 1
                            FROM role_assignments AS existing
                            WHERE existing.id <> NEW.id
                                AND existing.status = 'active'
                                AND existing.user_id = NEW.user_id
                                AND existing.role_id = NEW.role_id
                                AND existing.scope_id <=> NEW.scope_id
                                AND (existing.end_at IS NULL OR existing.end_at > NEW.start_at)
                                AND (NEW.end_at IS NULL OR NEW.end_at > existing.start_at)
                        ) THEN
                            SIGNAL SQLSTATE '45000'
                                SET MESSAGE_TEXT = 'role_assignments_active_overlap';
                        END IF;
                    END
                    SQL);
            }

            return;
        }

        if ($driver !== 'sqlite') {
            return;
        }

        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $name => $operation) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER role_assignments_{$name}_active_overlap_check
                BEFORE {$operation} ON role_assignments
                WHEN NEW.status = 'active'
                    AND EXISTS (
                        SELECT 1
                        FROM role_assignments AS existing
                        WHERE existing.id <> NEW.id
                            AND existing.status = 'active'
                            AND existing.user_id = NEW.user_id
                            AND existing.role_id = NEW.role_id
                            AND existing.scope_id IS NEW.scope_id
                            AND (existing.end_at IS NULL OR existing.end_at > NEW.start_at)
                            AND (NEW.end_at IS NULL OR NEW.end_at > existing.start_at)
                    )
                BEGIN
                    SELECT RAISE(ABORT, 'role_assignments_active_overlap');
                END
                SQL);
        }
    }

    private function addMySqlRoleAssignmentOverlapTriggers(): void
    {
        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $name => $operation) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER role_assignments_{$name}_active_overlap_check
                BEFORE {$operation} ON role_assignments
                FOR EACH ROW
                BEGIN
                    IF NEW.status = 'active' AND EXISTS (
                        SELECT 1
                        FROM role_assignments AS existing
                        WHERE existing.id <> NEW.id
                            AND existing.status = 'active'
                            AND existing.user_id = NEW.user_id
                            AND existing.role_id = NEW.role_id
                            AND existing.scope_id <=> NEW.scope_id
                            AND existing.scope_type <=> NEW.scope_type
                            AND (existing.end_at IS NULL OR existing.end_at > NEW.start_at)
                            AND (NEW.end_at IS NULL OR NEW.end_at > existing.start_at)
                    ) THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'role_assignments_active_overlap';
                    END IF;
                END
                SQL);
        }
    }

    private function addSqliteRoleAssignmentOverlapTriggers(): void
    {
        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $name => $operation) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER role_assignments_{$name}_active_overlap_check
                BEFORE {$operation} ON role_assignments
                WHEN NEW.status = 'active'
                    AND EXISTS (
                        SELECT 1
                        FROM role_assignments AS existing
                        WHERE existing.id <> NEW.id
                            AND existing.status = 'active'
                            AND existing.user_id = NEW.user_id
                            AND existing.role_id = NEW.role_id
                            AND existing.scope_id IS NEW.scope_id
                            AND existing.scope_type IS NEW.scope_type
                            AND (existing.end_at IS NULL OR existing.end_at > NEW.start_at)
                            AND (NEW.end_at IS NULL OR NEW.end_at > existing.start_at)
                    )
                BEGIN
                    SELECT RAISE(ABORT, 'role_assignments_active_overlap');
                END
                SQL);
        }
    }

    private function upgradeFieldAuditTimestampPrecision(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach (['classification_policies', 'field_access_templates'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::statement("ALTER TABLE {$table} MODIFY created_at DATETIME(3) NULL, MODIFY updated_at DATETIME(3) NULL");
        }
    }

    private function restoreFieldAuditTimestampPrecision(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach (['classification_policies', 'field_access_templates'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::statement("ALTER TABLE {$table} MODIFY created_at TIMESTAMP NULL, MODIFY updated_at TIMESTAMP NULL");
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        return array_filter(
            Schema::getIndexes($table),
            static fn (array $index): bool => $index['name'] === $name,
        ) !== [];
    }
};
