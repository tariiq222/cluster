<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Domain\UuidV7;
use Modules\Identity\Infrastructure\Security\PasswordHasher;

/**
 * Dedicated E2E fixture for the PlatformSettings closure. Creates four
 * Identity accounts, each holding only the exact active PlatformSettings
 * capabilities needed for its role, and provisions a real facility for
 * the test runtime. The fixture is idempotent and refuses to run outside
 * `local` and `testing`; production is never touched.
 *
 * Personas:
 *   - ps-e2e-full-owner    : owns settings, alerts, maintenance, calendars.
 *   - ps-e2e-operator      : reads health, reads settings, runs backups.
 *   - ps-e2e-unauthorized  : authenticated but holds no PlatformSettings
 *                            capabilities.
 *   - ps-e2e-deferred-logs : holds only the deferred technical-log caps so
 *                            the test can prove the API still returns 503
 *                            and the UI route stays hidden.
 *
 * The deferred technical-log capabilities are intentionally granted only
 * to the deferred-logs persona. The full owner and operator personas
 * never receive them. This keeps the deferred surface honest and the
 * production binding safe from accidental elevation.
 */
final class PlatformSettingsE2EAccountSeeder extends Seeder
{
    public const FULL_OWNER_ACCOUNT_ID = '019f8e3b-3368-7192-85a6-3da3949d0f01';

    public const OPERATOR_ACCOUNT_ID = '019f8e3b-3368-7192-85a6-3da3949d0f02';

    public const UNAUTHORIZED_ACCOUNT_ID = '019f8e3b-3368-7192-85a6-3da3949d0f03';

    public const DEFERRED_LOGS_ACCOUNT_ID = '019f8e3b-3368-7192-85a6-3da3949d0f04';

    public const FULL_OWNER_PERSON_ID = '019f8e3b-3368-7192-85a6-3da3949d0f11';

    public const OPERATOR_PERSON_ID = '019f8e3b-3368-7192-85a6-3da3949d0f12';

    public const UNAUTHORIZED_PERSON_ID = '019f8e3b-3368-7192-85a6-3da3949d0f13';

    public const DEFERRED_LOGS_PERSON_ID = '019f8e3b-3368-7192-85a6-3da3949d0f14';

    public const FULL_OWNER_USERNAME = 'ps-e2e-full-owner';

    public const OPERATOR_USERNAME = 'ps-e2e-operator';

    public const UNAUTHORIZED_USERNAME = 'ps-e2e-unauthorized';

    public const DEFERRED_LOGS_USERNAME = 'ps-e2e-deferred-logs';

    public const FULL_OWNER_PASSWORD = 'Platform!Full.Owner.E2E.2026';

    public const OPERATOR_PASSWORD = 'Platform!Operator.ReadOnly.E2E.2026';

    public const UNAUTHORIZED_PASSWORD = 'Platform!Unauth.NoCaps.E2E.2026';

    public const DEFERRED_LOGS_PASSWORD = 'Platform!DeferredLogs.E2E.2026';

    public const FACILITY_ID = '019f8e3b-3368-7192-85a6-3da3949d0f90';

    public const FULL_OWNER_ROLE_CODE = 'ps.e2e.full-owner';

    public const OPERATOR_ROLE_CODE = 'ps.e2e.operator';

    public const UNAUTHORIZED_ROLE_CODE = 'ps.e2e.unauthorized';

    public const DEFERRED_LOGS_ROLE_CODE = 'ps.e2e.deferred-logs';

    /** @var list<string> */
    public const FULL_OWNER_CAPABILITIES = [
        'platform_settings.read',
        'platform_settings.manage',
        'platform_settings.publish',
        'platform_settings.calendar.read',
        'platform_settings.calendar.manage',
        'platform_settings.calendar.override_official_holiday',
        'platform_operations.health.read',
        'platform_operations.backup.read',
        'platform_operations.backup.run',
        'platform_operations.restore.request',
        'platform_operations.restore.confirm',
        'platform_operations.alerts.manage',
        'platform_operations.maintenance.manage',
        'platform_operations.maintenance.cancel',
    ];

    /** @var list<string> */
    public const OPERATOR_CAPABILITIES = [
        'platform_settings.read',
        'platform_operations.health.read',
        'platform_operations.backup.read',
        'platform_operations.backup.run',
    ];

    /** @var list<string> */
    public const DEFERRED_LOGS_CAPABILITIES = [
        'platform_operations.logs.read',
        'platform_operations.logs.restore',
    ];

    public const ALERT_POLICY_ID = '019f8e3b-3368-7192-85a6-3da3949d0f31';

    public const ALERT_POLICY_CODE = 'ps.e2e.alert-policy';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \LogicException('PlatformSettings E2E fixtures are testing-only.');
        }

        $this->seedFacility();
        $this->call(AuthorizationCatalogSeeder::class);
        $this->seedPersonas();
        $this->seedAlertPolicy();
    }

    private function seedAlertPolicy(): void
    {
        $now = now();
        $existing = DB::table('platform_alert_policies')->where('id', self::ALERT_POLICY_ID)->first();
        if ($existing !== null) {
            // Reset to a known lock_version so the workflow tests can
            // drive a fresh optimistic-concurrency race without
            // inheriting state from a previous Playwright run.
            DB::table('platform_alert_policies')->where('id', self::ALERT_POLICY_ID)->update([
                'code' => self::ALERT_POLICY_CODE,
                'status' => 'active',
                'severity' => 'info',
                'channel' => 'in_app',
                'routing_policy' => json_encode(['recipient_capability' => 'platform_operations.alerts.manage'], JSON_THROW_ON_ERROR),
                'escalation_policy' => json_encode(['after_minutes' => 5], JSON_THROW_ON_ERROR),
                'lock_version' => 1,
                'updated_at' => $now,
            ]);

            return;
        }
        DB::table('platform_alert_policies')->insert([
            'id' => self::ALERT_POLICY_ID,
            'code' => self::ALERT_POLICY_CODE,
            'status' => 'active',
            'severity' => 'info',
            'channel' => 'in_app',
            'routing_policy' => json_encode(['recipient_capability' => 'platform_operations.alerts.manage'], JSON_THROW_ON_ERROR),
            'escalation_policy' => json_encode(['after_minutes' => 5], JSON_THROW_ON_ERROR),
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedFacility(): void
    {
        $now = now();
        $clusterId = DB::table('clusters')->where('singleton_key', 1)->value('id');
        if (! is_string($clusterId)) {
            $clusterId = '019f8e3b-3368-7192-85a6-3da3949d0fc0';
            DB::table('clusters')->insertOrIgnore([
                'id' => $clusterId,
                'singleton_key' => 1,
                'code' => 'PS-E2E-CLUSTER',
                'name_ar' => 'تجمع اختبار PlatformSettings',
                'name_en' => 'PlatformSettings E2E Cluster',
                'status' => 'active',
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $facilityTypeId = DB::table('facility_types')->where('code', 'center')->value('id');
        if (! is_string($facilityTypeId)) {
            $facilityTypeId = '0197f0e0-0000-7000-8000-000000000101';
        }
        DB::table('facilities')->insertOrIgnore([
            'id' => self::FACILITY_ID,
            'cluster_id' => $clusterId,
            'facility_type_id' => $facilityTypeId,
            'code' => 'ps-e2e-facility',
            'name_ar' => 'منشأة اختبار PlatformSettings',
            'name_en' => 'PlatformSettings E2E Facility',
            'status' => 'active',
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedPersonas(): void
    {
        $hasher = app(PasswordHasher::class);
        $personas = [
            [
                'account_id' => self::FULL_OWNER_ACCOUNT_ID,
                'person_id' => self::FULL_OWNER_PERSON_ID,
                'employee_number' => 'PS-E2E-FULL',
                'username' => self::FULL_OWNER_USERNAME,
                'display_ar' => 'مالك كامل لـ PlatformSettings',
                'display_en' => 'PlatformSettings full owner',
                'password' => self::FULL_OWNER_PASSWORD,
                'role_code' => self::FULL_OWNER_ROLE_CODE,
                'capabilities' => self::FULL_OWNER_CAPABILITIES,
            ],
            [
                'account_id' => self::OPERATOR_ACCOUNT_ID,
                'person_id' => self::OPERATOR_PERSON_ID,
                'employee_number' => 'PS-E2E-OPER',
                'username' => self::OPERATOR_USERNAME,
                'display_ar' => 'مشغل PlatformSettings',
                'display_en' => 'PlatformSettings operator',
                'password' => self::OPERATOR_PASSWORD,
                'role_code' => self::OPERATOR_ROLE_CODE,
                'capabilities' => self::OPERATOR_CAPABILITIES,
            ],
            [
                'account_id' => self::UNAUTHORIZED_ACCOUNT_ID,
                'person_id' => self::UNAUTHORIZED_PERSON_ID,
                'employee_number' => 'PS-E2E-UNAUTH',
                'username' => self::UNAUTHORIZED_USERNAME,
                'display_ar' => 'حساب غير مخوّل',
                'display_en' => 'PlatformSettings unauthorized',
                'password' => self::UNAUTHORIZED_PASSWORD,
                'role_code' => self::UNAUTHORIZED_ROLE_CODE,
                'capabilities' => [],
            ],
            [
                'account_id' => self::DEFERRED_LOGS_ACCOUNT_ID,
                'person_id' => self::DEFERRED_LOGS_PERSON_ID,
                'employee_number' => 'PS-E2E-DEFERRED',
                'username' => self::DEFERRED_LOGS_USERNAME,
                'display_ar' => 'حساب السجلات المؤجلة',
                'display_en' => 'PlatformSettings deferred logs',
                'password' => self::DEFERRED_LOGS_PASSWORD,
                'role_code' => self::DEFERRED_LOGS_ROLE_CODE,
                'capabilities' => self::DEFERRED_LOGS_CAPABILITIES,
            ],
        ];
        $unitTypeId = DB::table('unit_types')->where('code', 'department')->value('id');
        if (! is_string($unitTypeId)) {
            $unitTypeId = '0197f0e0-0000-7000-8000-000000000202';
        }
        $clusterId = DB::table('facilities')->where('id', self::FACILITY_ID)->value('cluster_id');
        $clusterId = is_string($clusterId) ? $clusterId : '019f8e3b-3368-7192-85a6-3da3949d0fc0';
        foreach ($personas as $persona) {
            $this->seedPersona($persona, $hasher, $unitTypeId, (string) $clusterId);
        }
    }

    /**
     * @param  array{account_id: string, person_id: string, employee_number: string, username: string, display_ar: string, display_en: string, password: string, role_code: string, capabilities: list<string>}  $persona
     */
    private function seedPersona(array $persona, PasswordHasher $hasher, string $unitTypeId, string $clusterId): void
    {
        $now = now();
        $unitId = $persona['account_id'].'-unit';
        $positionId = $persona['account_id'].'-position';
        $assignmentId = $persona['account_id'].'-assignment';

        DB::table('organization_units')->insertOrIgnore([
            'id' => $unitId,
            'cluster_id' => $clusterId,
            'parent_id' => self::FACILITY_ID,
            'parent_type' => 'facility',
            'unit_type_id' => $unitTypeId,
            'code' => 'ps-e2e-unit-'.substr($persona['account_id'], -4),
            'name_ar' => $persona['display_ar'],
            'status' => 'active',
            'path_cache' => '/'.$clusterId.'/'.self::FACILITY_ID.'/'.$unitId,
            'depth' => 2,
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('positions')->insertOrIgnore([
            'id' => $positionId,
            'organization_unit_id' => $unitId,
            'code' => 'ps-e2e-pos-'.substr($persona['account_id'], -4),
            'title_ar' => $persona['display_ar'],
            'is_active' => true,
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('people')->insertOrIgnore([
            'id' => $persona['person_id'],
            'employee_number' => $persona['employee_number'],
            'display_name_ar' => $persona['display_ar'],
            'display_name_en' => $persona['display_en'],
            'status' => 'active',
            'person_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('users')->insertOrIgnore([
            'id' => $persona['account_id'],
            'username' => $persona['username'],
            'person_id' => $persona['person_id'],
            'person_version' => 1,
            'display_name_ar' => $persona['display_ar'],
            'display_name_en' => $persona['display_en'],
            'status' => 'active',
            'must_change_password' => false,
            'password_version' => 1,
            'failed_login_count' => 0,
            'locked_until' => null,
            'lock_version' => 1,
            'is_admin' => false,
            'lockout_level' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('identity_person_account_claims')->insertOrIgnore([
            'person_id' => $persona['person_id'],
            'account_id' => $persona['account_id'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('assignments')->insertOrIgnore([
            'id' => $assignmentId,
            'person_id' => $persona['person_id'],
            'position_id' => $positionId,
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
            'is_primary' => true,
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (! DB::table('credentials')->where('user_id', $persona['account_id'])->exists()) {
            DB::table('credentials')->insert([
                'id' => UuidV7::generate(),
                'user_id' => $persona['account_id'],
                'password_hash' => $hasher->hash($persona['password']),
                'hash_algorithm' => $hasher->algorithm(),
                'password_changed_at' => $now,
                'policy_version' => 'identity-password-v1',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $roleId = $this->ensureRole($persona['role_code'], $persona['display_ar'], $persona['display_en']);
        $this->ensureRoleCapabilities($roleId, $persona['capabilities']);
        $this->ensureRoleAssignment($persona['account_id'], $roleId);
    }

    private function ensureRole(string $code, string $nameAr, string $nameEn): string
    {
        $now = now();
        $existing = DB::table('roles')->where('code', $code)->value('id');
        if (is_string($existing)) {
            return $existing;
        }
        $id = UuidV7::generate();
        DB::table('roles')->insert([
            'id' => $id,
            'code' => $code,
            'name_ar' => $nameAr,
            'name_en' => $nameEn,
            'role_type' => 'journey',
            'status' => 'active',
            'is_system_role' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /**
     * @param  list<string>  $capabilities
     */
    private function ensureRoleCapabilities(string $roleId, array $capabilities): void
    {
        $now = now();
        DB::table('role_capabilities')->where('role_id', $roleId)->delete();
        foreach ($capabilities as $capabilityCode) {
            $capabilityId = DB::table('capabilities')->where('capability_code', $capabilityCode)->value('id');
            if (! is_string($capabilityId)) {
                continue;
            }
            DB::table('role_capabilities')->insert([
                'role_id' => $roleId,
                'capability_id' => $capabilityId,
                'effect' => 'allow',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function ensureRoleAssignment(string $userId, string $roleId): void
    {
        $now = now();
        $exists = DB::table('role_assignments')
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->where('scope_id', self::FACILITY_ID)
            ->where('status', 'active')
            ->exists();
        if ($exists) {
            return;
        }
        DB::table('role_assignments')->insertOrIgnore([
            'id' => UuidV7::generate(),
            'user_id' => $userId,
            'role_id' => $roleId,
            'scope_type' => 'facility',
            'scope_id' => self::FACILITY_ID,
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
