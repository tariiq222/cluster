<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        try {
            Schema::create('audit_events', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->char('request_hash', 64);
                $table->string('stream_key', 160);
                $table->unsignedBigInteger('stream_sequence');
                $table->string('source_module', 64);
                $table->string('action', 128);
                $table->string('event_type', 160);
                $table->string('actor_type', 16);
                $table->uuid('actor_id')->nullable();
                $table->uuid('original_actor_id')->nullable();
                $table->string('subject_type', 64);
                $table->uuid('subject_id')->nullable();
                $table->uuid('correlation_id');
                $table->string('outcome', 16);
                $table->string('classification', 32);
                $table->json('context');
                $table->unsignedSmallInteger('context_schema_version');
                $table->string('redaction_policy_version', 32);
                $table->dateTime('occurred_at', 3);
                $table->dateTime('recorded_at', 3);
                $table->dateTime('retention_until', 3);
                $table->char('previous_hash', 64)->nullable();
                $table->char('event_hash', 64);
                $table->string('integrity_key_version', 32);

                $table->unique(
                    ['stream_key', 'stream_sequence'],
                    'audit_events_stream_sequence_unique',
                );
                $table->unique('event_hash', 'audit_events_event_hash_unique');
                $table->index(['recorded_at', 'id'], 'audit_events_recorded_index');
                $table->index(['actor_id', 'recorded_at', 'id'], 'audit_events_actor_recorded_index');
                $table->index(
                    ['subject_type', 'subject_id', 'recorded_at', 'id'],
                    'audit_events_subject_recorded_index',
                );
                $table->index(
                    ['correlation_id', 'recorded_at', 'id'],
                    'audit_events_correlation_recorded_index',
                );
                $table->index(
                    ['source_module', 'action', 'recorded_at', 'id'],
                    'audit_events_source_action_recorded_index',
                );
                $table->index(
                    ['classification', 'recorded_at', 'id'],
                    'audit_events_classification_recorded_index',
                );
                $table->index(
                    ['retention_until', 'stream_key', 'stream_sequence'],
                    'audit_events_retention_stream_sequence_index',
                );
            });

            Schema::create('audit_export_jobs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('principal_id');
                $table->uuid('facility_id')->nullable();
                $table->json('query');
                $table->char('query_hash', 64);
                $table->string('reason_redacted', 500);
                $table->string('format', 8);
                $table->dateTime('snapshot_recorded_at', 3);
                $table->string('status', 16);
                $table->unsignedBigInteger('event_count');
                $table->unsignedBigInteger('lock_version');
                $table->dateTime('expires_at', 3);
                $table->timestamps(3);

                $table->index(
                    ['principal_id', 'query_hash', 'snapshot_recorded_at'],
                    'audit_export_jobs_principal_query_snapshot_index',
                );
                $table->index(
                    ['principal_id', 'status', 'created_at'],
                    'audit_export_jobs_principal_status_created_index',
                );
                $table->index(
                    ['expires_at', 'status'],
                    'audit_export_jobs_expires_status_index',
                );
            });

            Schema::create('audit_integrity_checkpoints', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('stream_key', 160);
                $table->string('kind', 24);
                $table->unsignedBigInteger('first_sequence');
                $table->unsignedBigInteger('last_sequence');
                $table->unsignedBigInteger('event_count');
                $table->char('terminal_event_hash', 64);
                $table->char('previous_checkpoint_hash', 64)->nullable();
                $table->char('checkpoint_hash', 64);
                $table->string('integrity_key_version', 32);
                $table->string('status', 16);
                $table->uuid('actor_id')->nullable();
                $table->uuid('correlation_id');
                $table->json('details');
                $table->dateTime('verified_at', 3);
                $table->dateTime('created_at', 3);

                $table->unique(
                    ['stream_key', 'kind', 'last_sequence', 'status'],
                    'audit_integrity_checkpoints_stream_kind_last_status_unique',
                );
                $table->unique(
                    'checkpoint_hash',
                    'audit_integrity_checkpoints_hash_unique',
                );
                $table->index(
                    ['stream_key', 'last_sequence'],
                    'audit_integrity_checkpoints_stream_last_index',
                );
                $table->index(
                    ['status', 'verified_at'],
                    'audit_integrity_checkpoints_status_verified_index',
                );
                $table->index(
                    'correlation_id',
                    'audit_integrity_checkpoints_correlation_index',
                );
            });

            Schema::create('audit_idempotency_keys', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('principal_id');
                $table->string('operation', 96);
                $table->char('key_hash', 64);
                $table->char('request_hash', 64);
                $table->unsignedSmallInteger('response_status');
                $table->json('response_payload');
                $table->uuid('resource_id');
                $table->timestamps(3);

                $table->unique(
                    ['principal_id', 'operation', 'key_hash'],
                    'audit_idempotency_keys_principal_operation_key_unique',
                );
            });

            $this->addSupportedDatabaseChecks();
            $this->addImmutableGuards();
            $this->enforceAppendOnlyPrivileges();
        } catch (Throwable $exception) {
            try {
                $this->down();
            } catch (Throwable) {
            }

            throw $exception;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_idempotency_keys');
        Schema::dropIfExists('audit_integrity_checkpoints');
        Schema::dropIfExists('audit_export_jobs');
        $this->dropAuditEventGuards();
        Schema::dropIfExists('audit_events');
    }

    private function addSupportedDatabaseChecks(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE audit_events
                ADD CONSTRAINT audit_events_actor_type_check
                CHECK (actor_type IN ('user', 'service', 'system'))
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE audit_events
                ADD CONSTRAINT audit_events_outcome_check
                CHECK (outcome IN ('succeeded', 'denied', 'failed'))
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE audit_events
                ADD CONSTRAINT audit_events_classification_check
                CHECK (classification IN ('public', 'internal', 'confidential', 'top_secret'))
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE audit_events
                ADD CONSTRAINT audit_events_stream_sequence_check
                CHECK (stream_sequence > 0)
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE audit_events
                ADD CONSTRAINT audit_events_retention_check
                CHECK (retention_until > recorded_at)
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE audit_events
                ADD CONSTRAINT audit_events_request_hash_check
                CHECK (REGEXP_LIKE(request_hash, '^[0-9a-f]{64}$', 'c'))
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE audit_events
                ADD CONSTRAINT audit_events_previous_hash_check
                CHECK (previous_hash IS NULL OR REGEXP_LIKE(previous_hash, '^[0-9a-f]{64}$', 'c'))
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE audit_events
                ADD CONSTRAINT audit_events_event_hash_check
                CHECK (REGEXP_LIKE(event_hash, '^[0-9a-f]{64}$', 'c'))
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE audit_export_jobs
                ADD CONSTRAINT audit_export_jobs_format_check
                CHECK (format IN ('csv', 'ndjson'))
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE audit_export_jobs
                ADD CONSTRAINT audit_export_jobs_status_check
                CHECK (status IN ('ready', 'expired'))
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE audit_integrity_checkpoints
                ADD CONSTRAINT audit_integrity_checkpoints_kind_check
                CHECK (kind IN ('verification', 'retention_purge'))
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE audit_integrity_checkpoints
                ADD CONSTRAINT audit_integrity_checkpoints_status_check
                CHECK (status IN ('verified', 'violated'))
                SQL);

            return;
        }

        if ($driver !== 'sqlite') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_events_insert_check
            BEFORE INSERT ON audit_events
            WHEN
                NEW.actor_type NOT IN ('user', 'service', 'system')
                OR NEW.outcome NOT IN ('succeeded', 'denied', 'failed')
                OR NEW.classification NOT IN ('public', 'internal', 'confidential', 'top_secret')
                OR NEW.stream_sequence < 1
                OR julianday(NEW.recorded_at) IS NULL
                OR julianday(NEW.retention_until) IS NULL
                OR julianday(NEW.retention_until) <= julianday(NEW.recorded_at)
                OR length(NEW.request_hash) <> 64
                OR NEW.request_hash GLOB '*[^0-9a-f]*'
                OR (
                    NEW.previous_hash IS NOT NULL
                    AND (
                        length(NEW.previous_hash) <> 64
                        OR NEW.previous_hash GLOB '*[^0-9a-f]*'
                    )
                )
                OR length(NEW.event_hash) <> 64
                OR NEW.event_hash GLOB '*[^0-9a-f]*'
            BEGIN
                SELECT RAISE(ABORT, 'audit_events_check');
            END
            SQL);

        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $name => $operation) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER audit_export_jobs_{$name}_check
                BEFORE {$operation} ON audit_export_jobs
                WHEN
                    NEW.format NOT IN ('csv', 'ndjson')
                    OR NEW.status NOT IN ('ready', 'expired')
                BEGIN
                    SELECT RAISE(ABORT, 'audit_export_jobs_check');
                END
                SQL);
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_integrity_checkpoints_insert_check
            BEFORE INSERT ON audit_integrity_checkpoints
            WHEN
                NEW.kind NOT IN ('verification', 'retention_purge')
                OR NEW.status NOT IN ('verified', 'violated')
            BEGIN
                SELECT RAISE(ABORT, 'audit_integrity_checkpoints_check');
            END
            SQL);
    }

    private function enforceAppendOnlyPrivileges(): void
    {
        if (! config('audit.enforce_revoke')) {
            return;
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('REVOKE UPDATE, DELETE ON audit_events FROM PUBLIC');
    }

    private function addImmutableGuards(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_events_update_prevent
                BEFORE UPDATE ON audit_events
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'audit_events_immutable';
                END
                SQL);
            foreach (['update' => 'UPDATE', 'delete' => 'DELETE'] as $name => $operation) {
                DB::unprepared(<<<SQL
                    CREATE TRIGGER audit_integrity_checkpoints_{$name}_prevent
                    BEFORE {$operation} ON audit_integrity_checkpoints
                    FOR EACH ROW
                    BEGIN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'audit_integrity_checkpoints_immutable';
                    END
                    SQL);
            }

            return;
        }

        if ($driver !== 'sqlite') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_events_update_prevent
            BEFORE UPDATE ON audit_events
            BEGIN
                SELECT RAISE(ABORT, 'audit_events_immutable');
            END
            SQL);
        foreach (['update' => 'UPDATE', 'delete' => 'DELETE'] as $name => $operation) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER audit_integrity_checkpoints_{$name}_prevent
                BEFORE {$operation} ON audit_integrity_checkpoints
                BEGIN
                    SELECT RAISE(ABORT, 'audit_integrity_checkpoints_immutable');
                END
                SQL);
        }
    }

    private function dropAuditEventGuards(): void
    {
        foreach (['audit_events_insert_check', 'audit_events_update_prevent'] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }
};
