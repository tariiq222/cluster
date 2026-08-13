<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Authorization\Features\OperationsOffice\OperationsOfficeRoleCatalog;
use Tests\TestCase;

/**
 * Reversibility coverage for the migration repairs owned by closure Task 11.
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

    public function test_identity_credentials_down_restores_the_pre_credential_schema_when_empty(): void
    {
        $this->dropTables([
            'identity_auth_attempt_ledgers', 'identity_totp', 'identity_activation_tokens',
            'identity_password_history', 'credentials', 'identity_person_provisioning',
            'identity_person_event_watermarks', 'identity_inbox', 'identity_idempotency_keys',
            'identity_sessions', 'identity_person_account_claims', 'users',
        ]);
        $accounts = self::loadMigration(
            'Modules/Identity/Infrastructure/Persistence/Migrations/CreateIdentityAccountTables.php',
        );
        $credentials = self::loadMigration(
            'Modules/Identity/Infrastructure/Persistence/Migrations/ZAddIdentityCredentialCoreTables.php',
        );
        $accounts->up();
        $credentials->up();

        $credentials->down();

        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('identity_sessions'));
        $this->assertFalse(Schema::hasTable('credentials'));
        $this->assertFalse(Schema::hasTable('identity_password_history'));
        $this->assertFalse(Schema::hasTable('identity_activation_tokens'));
        $this->assertFalse(Schema::hasTable('identity_totp'));
        $this->assertFalse(Schema::hasTable('identity_auth_attempt_ledgers'));
        $this->assertFalse(Schema::hasColumn('users', 'is_admin'));
        $this->assertFalse(Schema::hasColumn('users', 'lockout_level'));
        $this->assertFalse(Schema::hasColumn('identity_sessions', 'csrf_token_hash'));
        $this->assertFalse(Schema::hasColumn('identity_sessions', 'mfa_verified'));

        $accounts->down();
        $this->assertFalse(Schema::hasTable('identity_sessions'));
        $this->assertFalse(Schema::hasTable('users'));
    }

    public function test_identity_accounts_down_requires_credentials_to_roll_back_first_even_when_empty(): void
    {
        $this->dropTables([
            'identity_auth_attempt_ledgers', 'identity_totp', 'identity_activation_tokens',
            'identity_password_history', 'credentials', 'identity_person_provisioning',
            'identity_person_event_watermarks', 'identity_inbox', 'identity_idempotency_keys',
            'identity_sessions', 'identity_person_account_claims', 'users',
        ]);
        $accounts = self::loadMigration(
            'Modules/Identity/Infrastructure/Persistence/Migrations/CreateIdentityAccountTables.php',
        );
        $credentials = self::loadMigration(
            'Modules/Identity/Infrastructure/Persistence/Migrations/ZAddIdentityCredentialCoreTables.php',
        );
        $accounts->up();
        $credentials->up();

        try {
            $accounts->down();
            $this->fail('Expected account rollback to require credential rollback first.');
        } catch (\LogicException $exception) {
            $this->assertSame('identity_credentials_must_rollback_first', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('credentials'));
        $credentials->down();
        $accounts->down();
        $this->assertFalse(Schema::hasTable('users'));
    }

    public function test_identity_credentials_down_refuses_to_destroy_nonempty_credential_state(): void
    {
        $this->dropTables([
            'identity_auth_attempt_ledgers', 'identity_totp', 'identity_activation_tokens',
            'identity_password_history', 'credentials', 'identity_person_provisioning',
            'identity_person_event_watermarks', 'identity_inbox', 'identity_idempotency_keys',
            'identity_sessions', 'identity_person_account_claims', 'users',
        ]);
        $accounts = self::loadMigration(
            'Modules/Identity/Infrastructure/Persistence/Migrations/CreateIdentityAccountTables.php',
        );
        $credentials = self::loadMigration(
            'Modules/Identity/Infrastructure/Persistence/Migrations/ZAddIdentityCredentialCoreTables.php',
        );
        $accounts->up();
        $credentials->up();
        $userId = '018f6f7d-0c00-7000-8000-000000000971';
        DB::table('users')->insert([
            'id' => $userId,
            'username' => 'migration-rollback-guard',
            'display_name_ar' => 'حارس التراجع',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('credentials')->insert([
            'id' => '018f6f7d-0c00-7000-8000-000000000972',
            'user_id' => $userId,
            'password_hash' => 'not-a-real-hash',
            'hash_algorithm' => 'test',
            'password_changed_at' => now(),
            'policy_version' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $credentials->down();
            $this->fail('Expected credential rollback to refuse destructive data loss.');
        } catch (\LogicException $exception) {
            $this->assertSame('identity_credentials_rollback_requires_empty_tables', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('credentials'));
        $this->assertSame(1, DB::table('credentials')->count());
        $this->assertTrue(Schema::hasColumn('users', 'is_admin'));
    }

    public function test_identity_mfa_required_column_up_adds_the_admin_like_flag_to_users(): void
    {
        $this->dropTables([
            'identity_auth_attempt_ledgers', 'identity_totp', 'identity_activation_tokens',
            'identity_password_history', 'credentials', 'identity_person_provisioning',
            'identity_person_event_watermarks', 'identity_inbox', 'identity_idempotency_keys',
            'identity_sessions', 'identity_person_account_claims', 'users',
        ]);
        $accounts = self::loadMigration(
            'Modules/Identity/Infrastructure/Persistence/Migrations/CreateIdentityAccountTables.php',
        );
        $credentials = self::loadMigration(
            'Modules/Identity/Infrastructure/Persistence/Migrations/ZAddIdentityCredentialCoreTables.php',
        );
        $mfa = self::loadMigration(
            'Modules/Identity/Infrastructure/Persistence/Migrations/ZAddIdentityMfaRequiredColumn.php',
        );
        $accounts->up();
        $credentials->up();

        $mfa->up();

        $this->assertTrue(Schema::hasColumn('users', 'mfa_required'));
        $userId = '018f6f7d-0c00-7000-8000-000000000973';
        DB::table('users')->insert([
            'id' => $userId,
            'username' => 'mfa-flag-guard',
            'display_name_ar' => 'حارس التحقق',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertFalse((bool) DB::table('users')->where('id', $userId)->value('mfa_required'));
        DB::table('users')->where('id', $userId)->update(['mfa_required' => true]);
        $this->assertTrue((bool) DB::table('users')->where('id', $userId)->value('mfa_required'));
    }

    public function test_identity_mfa_required_column_down_reverses_exactly(): void
    {
        $this->dropTables([
            'identity_auth_attempt_ledgers', 'identity_totp', 'identity_activation_tokens',
            'identity_password_history', 'credentials', 'identity_person_provisioning',
            'identity_person_event_watermarks', 'identity_inbox', 'identity_idempotency_keys',
            'identity_sessions', 'identity_person_account_claims', 'users',
        ]);
        $accounts = self::loadMigration(
            'Modules/Identity/Infrastructure/Persistence/Migrations/CreateIdentityAccountTables.php',
        );
        $credentials = self::loadMigration(
            'Modules/Identity/Infrastructure/Persistence/Migrations/ZAddIdentityCredentialCoreTables.php',
        );
        $mfa = self::loadMigration(
            'Modules/Identity/Infrastructure/Persistence/Migrations/ZAddIdentityMfaRequiredColumn.php',
        );
        $accounts->up();
        $credentials->up();
        $mfa->up();

        $mfa->down();

        $this->assertFalse(Schema::hasColumn('users', 'mfa_required'));
        $this->assertTrue(Schema::hasColumn('users', 'is_admin'));
        $this->assertTrue(Schema::hasTable('credentials'));
    }

    public function test_organization_seed_downs_remove_only_their_unreferenced_owned_rows(): void
    {
        $this->dropTables([
            'positions', 'organization_units', 'unit_types', 'organization_idempotency_keys',
            'facilities', 'facility_types', 'clusters',
        ]);
        $core = self::loadMigration(
            'Modules/Organization/Infrastructure/Persistence/Migrations/CreateOrganizationCoreTables.php',
        );
        $tree = self::loadMigration(
            'Modules/Organization/Infrastructure/Persistence/Migrations/CreateOrganizationTreeTables.php',
        );
        $facilityTypes = self::loadMigration(
            'Modules/Organization/Infrastructure/Persistence/Migrations/SeedOrganizationFacilityTypes.php',
        );
        $unitTypes = self::loadMigration(
            'Modules/Organization/Infrastructure/Persistence/Migrations/SeedOrganizationUnitTypes.php',
        );
        $core->up();
        $tree->up();
        $facilityTypes->up();
        $unitTypes->up();

        $unitTypes->down();
        $facilityTypes->down();

        $this->assertSame(0, DB::table('facility_types')->count());
        $this->assertSame(0, DB::table('unit_types')->count());
        $this->assertTrue(Schema::hasTable('facilities'));
        $this->assertTrue(Schema::hasTable('organization_units'));
    }

    public function test_facility_type_seed_down_refuses_to_delete_a_referenced_controlled_type(): void
    {
        $this->dropTables(['organization_idempotency_keys', 'facilities', 'facility_types', 'clusters']);
        $core = self::loadMigration(
            'Modules/Organization/Infrastructure/Persistence/Migrations/CreateOrganizationCoreTables.php',
        );
        $seed = self::loadMigration(
            'Modules/Organization/Infrastructure/Persistence/Migrations/SeedOrganizationFacilityTypes.php',
        );
        $core->up();
        $seed->up();
        DB::table('clusters')->insert([
            'id' => '018f6f7d-0c00-7000-8000-000000000981',
            'code' => 'ROLLBACK-GUARD',
            'name_ar' => 'تجمع الحماية',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('facilities')->insert([
            'id' => '018f6f7d-0c00-7000-8000-000000000982',
            'cluster_id' => '018f6f7d-0c00-7000-8000-000000000981',
            'facility_type_id' => '0197f0e0-0000-7000-8000-000000000101',
            'code' => 'ROLLBACK-GUARD-FACILITY',
            'name_ar' => 'منشأة الحماية',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $seed->down();
            $this->fail('Expected facility type rollback to refuse a referenced controlled type.');
        } catch (\LogicException $exception) {
            $this->assertSame('organization_facility_type_rollback_has_references', $exception->getMessage());
        }

        $this->assertSame(4, DB::table('facility_types')->count());
    }

    public function test_unit_type_seed_down_refuses_to_delete_a_referenced_controlled_type(): void
    {
        $this->dropTables([
            'positions', 'organization_units', 'unit_types', 'organization_idempotency_keys',
            'facilities', 'facility_types', 'clusters',
        ]);
        $core = self::loadMigration(
            'Modules/Organization/Infrastructure/Persistence/Migrations/CreateOrganizationCoreTables.php',
        );
        $tree = self::loadMigration(
            'Modules/Organization/Infrastructure/Persistence/Migrations/CreateOrganizationTreeTables.php',
        );
        $seed = self::loadMigration(
            'Modules/Organization/Infrastructure/Persistence/Migrations/SeedOrganizationUnitTypes.php',
        );
        $core->up();
        $tree->up();
        $seed->up();
        $clusterId = '018f6f7d-0c00-7000-8000-000000000983';
        DB::table('clusters')->insert([
            'id' => $clusterId,
            'code' => 'UNIT-ROLLBACK-GUARD',
            'name_ar' => 'تجمع حماية الوحدات',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('organization_units')->insert([
            'id' => '018f6f7d-0c00-7000-8000-000000000984',
            'cluster_id' => $clusterId,
            'parent_id' => $clusterId,
            'parent_type' => 'cluster',
            'unit_type_id' => '0197f0e0-0000-7000-8000-000000000201',
            'code' => 'UNIT-ROLLBACK-GUARD',
            'name_ar' => 'وحدة الحماية',
            'path_cache' => '/018f6f7d-0c00-7000-8000-000000000984',
            'depth' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $seed->down();
            $this->fail('Expected unit type rollback to refuse a referenced controlled type.');
        } catch (\LogicException $exception) {
            $this->assertSame('organization_unit_type_rollback_has_references', $exception->getMessage());
        }

        $this->assertSame(5, DB::table('unit_types')->count());
    }

    public function test_documents_w26_up_adds_and_down_drops_the_restriction_policy_key_column(): void
    {
        Schema::table('documents', function ($table): void {
            $table->dropColumn('restriction_policy_key');
        });
        $this->assertFalse(Schema::hasColumn('documents', 'restriction_policy_key'));

        $migration = self::loadMigration(
            'Modules/Documents/Infrastructure/Persistence/Migrations/W26AddDocumentRestrictionPolicyKey.php',
        );
        $migration->up();
        $this->assertTrue(Schema::hasColumn('documents', 'restriction_policy_key'));

        $migration->down();
        $this->assertFalse(Schema::hasColumn('documents', 'restriction_policy_key'));
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

    public function test_notifications_w20_down_restores_w18_uniqueness_and_preserves_the_w18_consumer_contract(): void
    {
        $this->dropTables(['notification_dead_letters', 'notification_recipients', 'notifications', 'notification_inbox']);
        Schema::create('notifications', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('recipient_user_id')->index();
            $table->string('title', 160);
            $table->boolean('is_read')->default(false);
            $table->unique(['event_id', 'recipient_user_id'], 'pre_w20_event_recipient_unique');
            $table->timestamps();
        });
        Schema::create('notification_inbox', function ($table): void {
            $table->uuid('event_id')->primary();
            $table->string('recipient_capability', 96)->nullable();
            $table->string('consumer', 96)->nullable();
            $table->timestamp('processed_at');
            $table->index(['consumer', 'processed_at'], 'notif_inbox_consumer_idx');
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
        $this->assertTrue(Schema::hasColumn('notification_inbox', 'consumer'));
        $this->assertTrue(Schema::hasIndex('notification_inbox', ['consumer', 'processed_at']));
    }

    public function test_authorization_w15_up_inserts_the_platform_owner_role(): void
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
        $this->assertContains('unrelated-role', $remainingCodes, 'Down must only remove office-owned roles.');
    }

    public function test_search_drop_inbox_removes_the_vestigial_table_and_down_restores_it(): void
    {
        $this->dropTables(['search_inbox']);
        $migration = self::loadMigration(
            'Modules/Search/Infrastructure/Persistence/Migrations/DropSearchInboxTable.php',
        );
        $migration->up();
        $this->assertFalse(Schema::hasTable('search_inbox'));
        $migration->down();
        $this->assertTrue(Schema::hasTable('search_inbox'));
        $this->assertTrue(Schema::hasColumns('search_inbox', [
            'id', 'event_id', 'event_type', 'created_at', 'updated_at',
        ]));
    }
}
