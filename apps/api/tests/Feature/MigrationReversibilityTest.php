<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Authorization\Features\OperationsOffice\OperationsOfficeRoleCatalog;
use Tests\TestCase;

/**
 * Reversibility coverage for the migrations owned by Task 14.
 *
 * Each test loads the migration by `require base_path(...)` so the assertion
 * is the same artifact the application ships.
 */
final class MigrationReversibilityTest extends TestCase
{
    use RefreshDatabase;

    private static function loadMigration(string $relative): object
    {
        $path = base_path($relative);
        self::assertFileExists($path, sprintf('Migration %s must exist on disk.', $relative));

        return require $path;
    }

    /** @param list<string> $tables */
    private function dropTables(array $tables): void
    {
        Schema::disableForeignKeyConstraints();
        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();
    }

    public function test_workflow_w15_up_creates_the_canonical_workflow_decisions_schema(): void
    {
        $this->dropTables(['workflow_decisions', 'workflow_step_instances', 'workflow_instances', 'workflow_versions', 'workflow_definitions']);
        Schema::create('workflow_definitions', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('code', 64);
            $table->string('title');
            $table->timestamps();
        });
        Schema::create('workflow_versions', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('workflow_definition_id');
            $table->unsignedInteger('version_number');
            $table->string('definition_state', 16);
            $table->json('graph');
            $table->timestamps();
        });
        Schema::create('workflow_instances', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('workflow_version_id');
            $table->string('subject_type', 64);
            $table->uuid('subject_id');
            $table->string('status', 16);
            $table->timestamps();
        });
        Schema::create('workflow_step_instances', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('workflow_instance_id');
            $table->string('step_key', 64);
            $table->string('status', 16);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
        });

        $migration = self::loadMigration(
            'Modules/Workflow/Infrastructure/Persistence/Migrations/W15CreateWorkflowDecisionsTable.php',
        );
        $migration->up();

        $this->assertTrue(Schema::hasTable('workflow_decisions'));
        $this->assertTrue(Schema::hasColumns('workflow_decisions', [
            'id', 'workflow_step_id', 'workflow_instance_id', 'workflow_version_id',
            'decision', 'reason', 'actor_user_id', 'correlation_id', 'graph_hash',
            'single_member_bootstrap_approval', 'decided_at',
        ]));
        $this->assertTrue(Schema::hasColumns('workflow_versions', [
            'is_system', 'review_state', 'submitted_by_user_id', 'submitted_at',
            'approved_by_user_id', 'approved_at', 'returned_by_user_id',
            'return_reason', 'single_member_bootstrap_approval',
        ]));
        $this->assertTrue(Schema::hasColumns('workflow_step_instances', ['assignment_rule', 'resolution_attempted_at']));
        $this->assertTrue(Schema::hasColumns('workflow_instances', ['returned_at', 'return_reason']));
        $this->assertTrue(
            Schema::hasIndex('workflow_decisions', ['workflow_step_id'], 'unique'),
            'workflow_decisions.workflow_step_id must remain uniquely indexed.',
        );
    }

    public function test_workflow_w15_down_restores_workflow_versions_and_drops_workflow_decisions(): void
    {
        $this->dropTables(['workflow_decisions', 'workflow_step_instances', 'workflow_instances', 'workflow_versions', 'workflow_definitions']);
        Schema::create('workflow_definitions', function ($table): void {
            $table->uuid('id')->primary();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });
        Schema::create('workflow_versions', function ($table): void {
            $table->uuid('id')->primary();
            $table->unsignedInteger('version_number');
            $table->string('definition_state', 16);
            $table->boolean('is_system')->default(false);
            $table->string('review_state', 24)->default('draft');
            $table->uuid('submitted_by_user_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->uuid('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('returned_by_user_id')->nullable();
            $table->text('return_reason')->nullable();
            $table->boolean('single_member_bootstrap_approval')->default(false);
            $table->timestamps();
        });
        Schema::create('workflow_instances', function ($table): void {
            $table->uuid('id')->primary();
            $table->text('return_reason')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();
        });
        Schema::create('workflow_step_instances', function ($table): void {
            $table->uuid('id')->primary();
            $table->json('assignment_rule')->nullable();
            $table->timestamp('resolution_attempted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('workflow_decisions', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('workflow_step_id')->nullable();
            $table->uuid('workflow_instance_id')->nullable();
            $table->uuid('workflow_version_id')->nullable();
            $table->string('decision', 24);
            $table->uuid('actor_user_id');
            $table->char('graph_hash', 64)->nullable();
            $table->boolean('single_member_bootstrap_approval')->default(false);
            $table->timestamp('decided_at');
            $table->timestamps();
        });

        $migration = self::loadMigration(
            'Modules/Workflow/Infrastructure/Persistence/Migrations/W15CreateWorkflowDecisionsTable.php',
        );
        $migration->down();

        $this->assertFalse(Schema::hasTable('workflow_decisions'));
        $this->assertFalse(Schema::hasColumn('workflow_versions', 'is_system'));
        $this->assertFalse(Schema::hasColumn('workflow_versions', 'review_state'));
        $this->assertFalse(Schema::hasColumn('workflow_versions', 'submitted_by_user_id'));
        $this->assertFalse(Schema::hasColumn('workflow_versions', 'approved_by_user_id'));
        $this->assertFalse(Schema::hasColumn('workflow_versions', 'returned_by_user_id'));
        $this->assertFalse(Schema::hasColumn('workflow_versions', 'single_member_bootstrap_approval'));
        $this->assertFalse(Schema::hasColumn('workflow_instances', 'returned_at'));
        $this->assertFalse(Schema::hasColumn('workflow_step_instances', 'resolution_attempted_at'));
        $this->assertFalse(Schema::hasColumn('workflow_definitions', 'is_system'));
    }

    public function test_workflow_w17_up_adds_approval_status_alongside_other_review_columns(): void
    {
        $this->dropTables(['workflow_versions']);
        Schema::create('workflow_versions', function ($table): void {
            $table->uuid('id')->primary();
            $table->unsignedInteger('version_number');
            $table->string('definition_state', 16);
            $table->timestamps();
        });

        $migration = self::loadMigration(
            'Modules/Workflow/Infrastructure/Persistence/Migrations/W17AddApprovalColumnsToWorkflowVersions.php',
        );
        $migration->up();

        $this->assertTrue(Schema::hasColumns('workflow_versions', [
            'submitted_by_user_id', 'submitted_at', 'approved_by_user_id',
            'approved_at', 'rejection_reason', 'approval_status', 'review_state',
            'usage_description', 'scope', 'single_member_bootstrap_approval',
        ]));
    }

    public function test_workflow_w17_down_drops_approval_status_along_with_other_review_columns(): void
    {
        $this->dropTables(['workflow_versions']);
        Schema::create('workflow_versions', function ($table): void {
            $table->uuid('id')->primary();
            $table->unsignedInteger('version_number');
            $table->string('definition_state', 16);
            $table->uuid('submitted_by_user_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->uuid('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('approval_status', 16)->default('draft');
            $table->string('review_state', 16)->default('draft');
            $table->text('usage_description')->nullable();
            $table->json('scope')->nullable();
            $table->boolean('single_member_bootstrap_approval')->default(false);
            $table->timestamps();
        });

        $migration = self::loadMigration(
            'Modules/Workflow/Infrastructure/Persistence/Migrations/W17AddApprovalColumnsToWorkflowVersions.php',
        );
        $migration->down();

        $this->assertFalse(Schema::hasColumn('workflow_versions', 'approval_status'));
        $this->assertFalse(Schema::hasColumn('workflow_versions', 'submitted_by_user_id'));
        $this->assertFalse(Schema::hasColumn('workflow_versions', 'approved_by_user_id'));
        $this->assertFalse(Schema::hasColumn('workflow_versions', 'rejection_reason'));
        $this->assertFalse(Schema::hasColumn('workflow_versions', 'review_state'));
        $this->assertFalse(Schema::hasColumn('workflow_versions', 'single_member_bootstrap_approval'));
    }

    public function test_notifications_w18_up_adds_columns_indexes_and_delivery_tables(): void
    {
        $this->dropTables(['notification_dead_letters', 'notification_recipients', 'notifications', 'notification_inbox']);
        Schema::create('notifications', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id')->unique();
            $table->uuid('recipient_user_id')->index();
            $table->string('title', 160);
            $table->uuid('source_record_id');
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });
        Schema::create('notification_inbox', function ($table): void {
            $table->uuid('event_id')->primary();
            $table->uuid('source_record_id')->nullable();
            $table->timestamp('processed_at');
            $table->timestamps();
        });

        $migration = self::loadMigration(
            'Modules/Notifications/Infrastructure/Persistence/Migrations/W18CreateNotificationDeliveryTables.php',
        );
        $migration->up();

        $this->assertTrue(Schema::hasColumns('notifications', ['status', 'notification_group_key', 'aggregation_count', 'last_event_id']));
        $this->assertTrue(Schema::hasColumns('notification_inbox', ['consumer']));
        $this->assertTrue(Schema::hasIndex('notification_inbox', ['consumer', 'processed_at']));
        $this->assertTrue(Schema::hasTable('notification_recipients'));
        $this->assertTrue(Schema::hasTable('notification_dead_letters'));
    }

    public function test_notifications_w18_down_restores_pre_w18_notification_columns_and_indexes(): void
    {
        $this->dropTables(['notification_dead_letters', 'notification_recipients', 'notifications', 'notification_inbox']);
        Schema::create('notifications', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('recipient_user_id')->index();
            $table->string('title', 160);
            $table->uuid('source_record_id');
            $table->boolean('is_read')->default(false);
            $table->string('status', 16)->default('unread');
            $table->string('notification_group_key', 191)->nullable();
            $table->unsignedInteger('aggregation_count')->default(1);
            $table->uuid('last_event_id')->nullable();
            $table->index(['recipient_user_id', 'notification_group_key'], 'notif_recipient_group_idx');
            $table->timestamps();
        });
        Schema::create('notification_inbox', function ($table): void {
            $table->uuid('event_id')->primary();
            $table->uuid('source_record_id')->nullable();
            $table->timestamp('processed_at');
            $table->string('consumer', 96)->default('notifications');
            $table->index(['consumer', 'processed_at'], 'notif_inbox_consumer_idx');
            $table->timestamps();
        });
        Schema::create('notification_recipients', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('notification_id');
            $table->uuid('recipient_user_id');
            $table->timestamps();
        });
        Schema::create('notification_dead_letters', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('source_stream', 128);
            $table->timestamps();
        });

        $migration = self::loadMigration(
            'Modules/Notifications/Infrastructure/Persistence/Migrations/W18CreateNotificationDeliveryTables.php',
        );
        $migration->down();

        $this->assertFalse(Schema::hasTable('notification_recipients'));
        $this->assertFalse(Schema::hasTable('notification_dead_letters'));
        $this->assertFalse(Schema::hasColumn('notifications', 'status'));
        $this->assertFalse(Schema::hasColumn('notifications', 'notification_group_key'));
        $this->assertFalse(Schema::hasColumn('notifications', 'aggregation_count'));
        $this->assertFalse(Schema::hasColumn('notifications', 'last_event_id'));
        $this->assertFalse(Schema::hasIndex('notifications', ['recipient_user_id', 'notification_group_key']));
        $this->assertFalse(Schema::hasColumn('notification_inbox', 'consumer'));
        $this->assertFalse(Schema::hasIndex('notification_inbox', ['consumer', 'processed_at']));
    }

    public function test_notifications_w20_up_replaces_event_id_unique_with_composite_event_recipient_unique(): void
    {
        $this->dropTables(['notification_dead_letters', 'notification_recipients', 'notifications', 'notification_inbox']);
        Schema::create('notifications', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id')->unique();
            $table->uuid('recipient_user_id')->index();
            $table->string('title', 160);
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });
        Schema::create('notification_inbox', function ($table): void {
            $table->uuid('event_id')->primary();
            $table->timestamp('processed_at');
            $table->timestamps();
        });

        $migration = self::loadMigration(
            'Modules/Notifications/Infrastructure/Persistence/Migrations/W20UpgradeTechnicalAlertFanoutSchema.php',
        );
        $migration->up();

        $this->assertTrue(Schema::hasColumns('notification_inbox', ['recipient_capability', 'consumer']));
        $this->assertTrue(
            Schema::hasIndex('notifications', ['event_id', 'recipient_user_id'], 'unique'),
            'W20 up must add the composite (event_id, recipient_user_id) unique index.',
        );
        $this->assertFalse(
            Schema::hasIndex('notifications', ['event_id'], 'unique'),
            'W20 up must drop the legacy event_id-only unique index.',
        );
    }

    public function test_notifications_w20_down_restores_legacy_event_id_unique_and_removes_inbox_columns(): void
    {
        $this->dropTables(['notification_dead_letters', 'notification_recipients', 'notifications', 'notification_inbox']);
        Schema::create('notifications', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('recipient_user_id')->index();
            $table->string('title', 160);
            $table->boolean('is_read')->default(false);
            $table->unique(['event_id', 'recipient_user_id'], 'notifications_event_recipient_unique');
            $table->timestamps();
        });
        Schema::create('notification_inbox', function ($table): void {
            $table->uuid('event_id')->primary();
            $table->string('recipient_capability', 96)->nullable();
            $table->string('consumer', 96)->nullable();
            $table->timestamp('processed_at');
            $table->timestamps();
        });

        $migration = self::loadMigration(
            'Modules/Notifications/Infrastructure/Persistence/Migrations/W20UpgradeTechnicalAlertFanoutSchema.php',
        );
        $migration->down();

        $this->assertFalse(
            Schema::hasIndex('notifications', ['event_id', 'recipient_user_id'], 'unique'),
            'W20 down must drop the composite (event_id, recipient_user_id) unique index exactly.',
        );
        $this->assertTrue(
            Schema::hasIndex('notifications', ['event_id'], 'unique'),
            'W20 down must restore the legacy event_id unique index exactly.',
        );
        $this->assertFalse(Schema::hasColumn('notification_inbox', 'recipient_capability'));
        $this->assertFalse(Schema::hasColumn('notification_inbox', 'consumer'));
    }

    public function test_authorization_w15_up_inserts_the_two_office_roles(): void
    {
        $this->dropTables(['role_assignments', 'role_capabilities', 'roles', 'capabilities']);
        Schema::create('roles', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('code', 96)->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('role_type', 32);
            $table->string('status', 16)->default('active');
            $table->boolean('is_system_role')->default(false);
            $table->timestamps();
        });
        Schema::create('capabilities', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('module_code', 64);
            $table->string('capability_code', 96);
            $table->string('action', 32);
            $table->string('sensitivity', 16)->default('normal');
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->unique(['module_code', 'capability_code']);
        });
        Schema::create('role_capabilities', function ($table): void {
            $table->uuid('role_id');
            $table->uuid('capability_id');
            $table->string('effect', 8)->default('allow');
            $table->timestamps();
            $table->primary(['role_id', 'capability_id']);
        });
        Schema::create('role_assignments', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('role_id');
            $table->uuid('user_id');
            $table->timestamps();
        });

        $migration = self::loadMigration(
            'Modules/Authorization/Infrastructure/Persistence/Migrations/W15CreateOperationsOffice.php',
        );
        $migration->up();

        $codes = DB::table('roles')->pluck('code')->all();
        $this->assertContains(OperationsOfficeRoleCatalog::PLATFORM_OWNER_ROLE, $codes);
        $this->assertContains(OperationsOfficeRoleCatalog::OFFICE_MEMBER_ROLE, $codes);
    }

    public function test_authorization_w15_down_removes_only_office_owned_rows(): void
    {
        $this->dropTables(['role_assignments', 'role_capabilities', 'roles', 'capabilities']);
        Schema::create('roles', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('code', 96)->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('role_type', 32);
            $table->string('status', 16)->default('active');
            $table->boolean('is_system_role')->default(false);
            $table->timestamps();
        });
        Schema::create('capabilities', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('module_code', 64);
            $table->string('capability_code', 96);
            $table->string('action', 32);
            $table->string('sensitivity', 16)->default('normal');
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->unique(['module_code', 'capability_code']);
        });
        Schema::create('role_capabilities', function ($table): void {
            $table->uuid('role_id');
            $table->uuid('capability_id');
            $table->string('effect', 8)->default('allow');
            $table->timestamps();
            $table->primary(['role_id', 'capability_id']);
        });
        Schema::create('role_assignments', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('role_id');
            $table->uuid('user_id');
            $table->timestamps();
        });

        $migration = self::loadMigration(
            'Modules/Authorization/Infrastructure/Persistence/Migrations/W15CreateOperationsOffice.php',
        );
        $migration->up();

        DB::table('roles')->insert([
            'id' => '0197f0e0-0000-7000-8000-000000009901',
            'code' => 'unrelated-role',
            'name_ar' => 'دور غير مرتبط',
            'name_en' => 'Unrelated role',
            'role_type' => 'custom',
            'status' => 'active',
            'is_system_role' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->down();

        $remainingCodes = DB::table('roles')->pluck('code')->all();
        $this->assertNotContains(OperationsOfficeRoleCatalog::PLATFORM_OWNER_ROLE, $remainingCodes);
        $this->assertNotContains(OperationsOfficeRoleCatalog::OFFICE_MEMBER_ROLE, $remainingCodes);
        $this->assertContains('unrelated-role', $remainingCodes, 'Down must only remove office-owned roles.');
    }
}
