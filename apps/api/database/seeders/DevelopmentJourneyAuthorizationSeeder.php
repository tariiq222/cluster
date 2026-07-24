<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\CapabilityCatalog;
use Modules\Authorization\Domain\UuidV7;
use Modules\Authorization\Features\OperationsOffice\BootstrapOperationsOffice;
use Modules\Authorization\Infrastructure\Persistence\AuthorizationBootstrapState;
use Modules\Identity\Infrastructure\Security\PasswordHasher;

/**
 * Development journey support: creates two ordinary Identity accounts and
 * gives them a real R1 operator role scoped to their facilities so browser/API
 * journeys run on the real RBAC+ABAC engine. Idempotent; only ever invoked by
 * local dev and e2e scripts, never by production seeds.
 */
final class DevelopmentJourneyAuthorizationSeeder extends Seeder
{
    public const ACCOUNT_A_ID = '018f6f7d-0c00-7000-8000-000000000021';

    public const ACCOUNT_B_ID = '018f6f7d-0c00-7000-8000-000000000022';

    public const PLATFORM_ADMIN_ACCOUNT_ID = '018f6f7d-0c00-7000-8000-000000000023';

    public const PLATFORM_ADMIN_PERSON_ID = '018f6f7d-0c00-7000-8000-000000000033';

    public const PLATFORM_ADMIN_UNIT_ID = '018f6f7d-0c00-7000-8000-000000000043';

    public const PLATFORM_ADMIN_POSITION_ID = '018f6f7d-0c00-7000-8000-000000000053';

    public const PLATFORM_ADMIN_ASSIGNMENT_ID = '018f6f7d-0c00-7000-8000-000000000063';

    public const FACILITY_A_ID = '018f6f7d-0c00-7000-8000-000000000011';

    public const FACILITY_B_ID = '018f6f7d-0c00-7000-8000-000000000012';

    public const ACCOUNT_A_USERNAME = 'w13-e2e-account-a';

    public const ACCOUNT_B_USERNAME = 'w13-e2e-account-b';

    public const ACCOUNT_A_PASSWORD = 'North!River7Quartz2026';

    public const ACCOUNT_B_PASSWORD = 'Cedar!Orbit8Harbor2026';

    public const PLATFORM_ADMIN_USERNAME = 'platform-admin';

    public const PLATFORM_ADMIN_PASSWORD = 'Admin!Cluster9Owner2026';

    public const ROLE_CODE = 'journey.r1-operator';

    public const AUTHORIZATION_ROLE_CODE = 'journey.w13-authorization-admin';

    private const GRANTS = [
        ['user_id' => self::ACCOUNT_A_ID, 'scope_type' => 'facility', 'scope_id' => self::FACILITY_A_ID],
        ['user_id' => self::ACCOUNT_B_ID, 'scope_type' => 'facility', 'scope_id' => self::FACILITY_B_ID],
    ];

    private const MODULES = [
        'work_record', 'work_definition', 'workflow', 'tasks', 'documents',
        'search', 'reporting', 'notifications', 'organization', 'identity',
    ];

    private const AUTHORIZATION_CAPABILITIES = [
        'authorization.role.read', 'authorization.role.manage',
        'authorization.capability.read', 'authorization.capability.manage',
        'authorization.assignment.read', 'authorization.assignment.manage',
        'authorization.delegation.read', 'authorization.delegation.manage',
        'authorization.deny.read', 'authorization.deny.manage',
        'authorization.policy.read', 'authorization.policy.manage',
        'authorization.audit.read', 'authorization.decision.read',
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \LogicException('Development journey authorization fixtures are testing-only.');
        }

        $this->seedIdentityAccounts();
        $this->call(AuthorizationCatalogSeeder::class);
        $platformClusterId = DB::table('clusters')->where('singleton_key', 1)->value('id');
        if (is_string($platformClusterId)) {
            app(BootstrapOperationsOffice::class)->bootstrap(self::PLATFORM_ADMIN_ACCOUNT_ID, $platformClusterId);
        }
        $bootstrap = app(AuthorizationBootstrapState::class);
        if ($bootstrap->isPending()) {
            $reason = 'Local development journey fixture';
            $bootstrap->complete(
                self::PLATFORM_ADMIN_ACCOUNT_ID,
                $reason,
                'development-journey-authorization-bootstrap',
                hash('sha256', json_encode(['reason' => $reason], JSON_THROW_ON_ERROR)),
            );
        }

        $now = now();
        $roleId = DB::table('roles')->where('code', self::ROLE_CODE)->value('id');
        if (! is_string($roleId)) {
            $roleId = UuidV7::generate();
            DB::table('roles')->insert([
                'id' => $roleId,
                'code' => self::ROLE_CODE,
                'name_ar' => 'مشغل رحلات R1',
                'name_en' => 'R1 journey operator',
                'role_type' => 'journey',
                'status' => 'active',
                'is_system_role' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $authorizationRoleId = DB::table('roles')->where('code', self::AUTHORIZATION_ROLE_CODE)->value('id');
        if (! is_string($authorizationRoleId)) {
            $authorizationRoleId = UuidV7::generate();
            DB::table('roles')->insert([
                'id' => $authorizationRoleId,
                'code' => self::AUTHORIZATION_ROLE_CODE,
                'name_ar' => 'مسؤول صلاحيات رحلة W1.3',
                'name_en' => 'W1.3 authorization administrator',
                'role_type' => 'journey',
                'status' => 'active',
                'is_system_role' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $codes = array_values(array_filter(
            CapabilityCatalog::all(),
            static fn (string $code): bool => in_array(explode('.', $code, 2)[0], self::MODULES, true)
                || in_array($code, self::AUTHORIZATION_CAPABILITIES, true),
        ));
        foreach ($codes as $code) {
            $capabilityId = DB::table('capabilities')->where('capability_code', $code)->value('id');
            if (! is_string($capabilityId)) {
                continue;
            }
            DB::table('role_capabilities')->insertOrIgnore([
                'role_id' => in_array($code, self::AUTHORIZATION_CAPABILITIES, true) ? $authorizationRoleId : $roleId,
                'capability_id' => $capabilityId,
                'effect' => 'allow',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (self::GRANTS as $grant) {
            $exists = DB::table('role_assignments')
                ->where('user_id', $grant['user_id'])
                ->where('role_id', $roleId)
                ->where('scope_id', $grant['scope_id'])
                ->where('status', 'active')
                ->exists();
            if ($exists) {
                continue;
            }
            DB::table('role_assignments')->insertOrIgnore([
                'id' => UuidV7::generate(),
                'user_id' => $grant['user_id'],
                'role_id' => $roleId,
                'scope_type' => $grant['scope_type'],
                'scope_id' => $grant['scope_id'],
                'start_at' => '2026-01-01 00:00:00.000',
                'end_at' => null,
                'status' => 'active',
                'granted_by_user_id' => $grant['user_id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $authorizationAssignmentExists = DB::table('role_assignments')
            ->where('user_id', self::ACCOUNT_A_ID)
            ->where('role_id', $authorizationRoleId)
            ->where('scope_id', self::FACILITY_A_ID)
            ->where('status', 'active')
            ->exists();
        if (! $authorizationAssignmentExists) {
            DB::table('role_assignments')->insertOrIgnore([
                'id' => UuidV7::generate(),
                'user_id' => self::ACCOUNT_A_ID,
                'role_id' => $authorizationRoleId,
                'scope_type' => 'facility',
                'scope_id' => self::FACILITY_A_ID,
                'start_at' => '2026-01-01 00:00:00.000',
                'end_at' => null,
                'status' => 'active',
                'granted_by_user_id' => self::ACCOUNT_A_ID,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Create the real Identity storage rows used by the browser journey.
     * These are ordinary users/credentials/person claims, not fixture bearer
     * accounts, and are deliberately limited to local/testing environments.
     */
    private function seedIdentityAccounts(): void
    {
        $now = now();
        $clusterId = '018f6f7d-0c00-7000-8000-00000000c113';
        $facilityTypeId = '0197f0e0-0000-7000-8000-000000000101';
        $unitTypeId = '0197f0e0-0000-7000-8000-000000000204';
        $people = [
            [
                'id' => '018f6f7d-0c00-7000-8000-000000000031',
                'employee_number' => 'W13-E2E-A',
                'display_name_ar' => 'حساب اختبار W1.3 أ',
                'display_name_en' => 'W1.3 E2E Account A',
                'account_id' => self::ACCOUNT_A_ID,
                'username' => self::ACCOUNT_A_USERNAME,
                'password' => self::ACCOUNT_A_PASSWORD,
                'facility_id' => self::FACILITY_A_ID,
            ],
            [
                'id' => '018f6f7d-0c00-7000-8000-000000000032',
                'employee_number' => 'W13-E2E-B',
                'display_name_ar' => 'حساب اختبار W1.3 ب',
                'display_name_en' => 'W1.3 E2E Account B',
                'account_id' => self::ACCOUNT_B_ID,
                'username' => self::ACCOUNT_B_USERNAME,
                'password' => self::ACCOUNT_B_PASSWORD,
                'facility_id' => self::FACILITY_B_ID,
            ],
            [
                'id' => self::PLATFORM_ADMIN_PERSON_ID,
                'employee_number' => 'LOCAL-PLATFORM-ADMIN',
                'display_name_ar' => 'مدير المنصة',
                'display_name_en' => 'Platform administrator',
                'account_id' => self::PLATFORM_ADMIN_ACCOUNT_ID,
                'username' => self::PLATFORM_ADMIN_USERNAME,
                'password' => self::PLATFORM_ADMIN_PASSWORD,
                'facility_id' => self::FACILITY_A_ID,
            ],
        ];

        $existingClusterId = DB::table('clusters')->where('singleton_key', 1)->value('id');
        if (is_string($existingClusterId)) {
            $clusterId = $existingClusterId;
        } elseif (! DB::table('clusters')->where('id', $clusterId)->exists()) {
            DB::table('clusters')->insert([
                'id' => $clusterId,
                'singleton_key' => 1,
                'code' => 'W13-E2E-CLUSTER',
                'name_ar' => 'تجمع اختبار W1.3',
                'name_en' => 'W1.3 E2E Cluster',
                'status' => 'active',
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        foreach ([
            ['id' => self::FACILITY_A_ID, 'code' => 'w13-e2e-facility-a', 'name_ar' => 'منشأة اختبار W1.3 أ', 'name_en' => 'W1.3 E2E Facility A'],
            ['id' => self::FACILITY_B_ID, 'code' => 'w13-e2e-facility-b', 'name_ar' => 'منشأة اختبار W1.3 ب', 'name_en' => 'W1.3 E2E Facility B'],
        ] as $facility) {
            DB::table('facilities')->insertOrIgnore([
                ...$facility,
                'cluster_id' => $clusterId,
                'facility_type_id' => $facilityTypeId,
                'status' => 'active',
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $hasher = app(PasswordHasher::class);
        foreach ($people as $person) {
            DB::table('people')->insertOrIgnore([
                'id' => $person['id'],
                'employee_number' => $person['employee_number'],
                'display_name_ar' => $person['display_name_ar'],
                'display_name_en' => $person['display_name_en'],
                'status' => 'active',
                'person_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('users')->insertOrIgnore([
                'id' => $person['account_id'],
                'username' => $person['username'],
                'person_id' => $person['id'],
                'person_version' => 1,
                'display_name_ar' => $person['display_name_ar'],
                'display_name_en' => $person['display_name_en'],
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
            if ($person['account_id'] === self::PLATFORM_ADMIN_ACCOUNT_ID) {
                DB::table('users')->where('id', $person['account_id'])->update([
                    'is_admin' => false,
                    'updated_at' => $now,
                ]);
            }
            DB::table('identity_person_account_claims')->insertOrIgnore([
                'person_id' => $person['id'],
                'account_id' => $person['account_id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $credentialExists = DB::table('credentials')->where('user_id', $person['account_id'])->exists();
            if (! $credentialExists) {
                $passwordHash = $hasher->hash($person['password']);
                DB::table('credentials')->insert([
                    'id' => UuidV7::generate(),
                    'user_id' => $person['account_id'],
                    'password_hash' => $passwordHash,
                    'hash_algorithm' => $hasher->algorithm(),
                    'password_changed_at' => $now,
                    'policy_version' => 'identity-password-v1',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('identity_password_history')->insertOrIgnore([
                    'user_id' => $person['account_id'],
                    'password_version' => 1,
                    'password_hash' => $passwordHash,
                    'hash_algorithm' => $hasher->algorithm(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            if ($person['facility_id'] === null) {
                continue;
            }

            $unitId = $person['account_id'] === self::PLATFORM_ADMIN_ACCOUNT_ID
                ? self::PLATFORM_ADMIN_UNIT_ID
                : ($person['facility_id'] === self::FACILITY_A_ID
                    ? '018f6f7d-0c00-7000-8000-000000000041'
                    : '018f6f7d-0c00-7000-8000-000000000042');
            $positionId = $person['account_id'] === self::PLATFORM_ADMIN_ACCOUNT_ID
                ? self::PLATFORM_ADMIN_POSITION_ID
                : ($person['facility_id'] === self::FACILITY_A_ID
                    ? '018f6f7d-0c00-7000-8000-000000000051'
                    : '018f6f7d-0c00-7000-8000-000000000052');
            $assignmentId = $person['account_id'] === self::PLATFORM_ADMIN_ACCOUNT_ID
                ? self::PLATFORM_ADMIN_ASSIGNMENT_ID
                : ($person['facility_id'] === self::FACILITY_A_ID
                    ? '018f6f7d-0c00-7000-8000-000000000061'
                    : '018f6f7d-0c00-7000-8000-000000000062');
            DB::table('organization_units')->insertOrIgnore([
                'id' => $unitId,
                'cluster_id' => $clusterId,
                'parent_id' => $person['facility_id'],
                'parent_type' => 'facility',
                'unit_type_id' => $unitTypeId,
                'code' => $person['account_id'] === self::PLATFORM_ADMIN_ACCOUNT_ID
                    ? 'local-platform-admin-unit'
                    : 'w13-e2e-unit-'.($person['facility_id'] === self::FACILITY_A_ID ? 'a' : 'b'),
                'name_ar' => $person['account_id'] === self::PLATFORM_ADMIN_ACCOUNT_ID ? 'إدارة المنصة' : 'وحدة اختبار W1.3',
                'status' => 'active',
                'path_cache' => '/'.$clusterId.'/'.$person['facility_id'].'/'.$unitId,
                'depth' => 2,
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('positions')->insertOrIgnore([
                'id' => $positionId,
                'organization_unit_id' => $unitId,
                'code' => $person['account_id'] === self::PLATFORM_ADMIN_ACCOUNT_ID ? 'LOCAL-PLATFORM-ADMIN' : 'W13-E2E-POS',
                'title_ar' => $person['account_id'] === self::PLATFORM_ADMIN_ACCOUNT_ID ? 'مدير المنصة' : 'منصب اختبار W1.3',
                'is_active' => true,
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('assignments')->insertOrIgnore([
                'id' => $assignmentId,
                'person_id' => $person['id'],
                'position_id' => $positionId,
                'start_at' => '2026-01-01 00:00:00.000',
                'end_at' => null,
                'is_primary' => true,
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
