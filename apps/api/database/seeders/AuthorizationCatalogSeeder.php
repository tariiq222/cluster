<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\CapabilityCatalog;
use Modules\Authorization\Domain\UuidV7;

final class AuthorizationCatalogSeeder extends Seeder
{
    /** @var list<string> */
    private const SENSITIVE_ACTIONS = ['manage', 'approve', 'publish', 'accept', 'grant', 'hold'];

    /**
     * Read capabilities whose resources are classified 'confidential' by their
     * controllers. Grants of these capabilities must convey CONFIDENTIAL
     * clearance, otherwise the RBAC+ABAC engine permanently denies them with
     * 'classification_insufficient'.
     *
     * @var list<string>
     */
    private const SENSITIVE_CAPABILITIES = [
        'organization.person.read',
        'organization.person.reference',
        'organization.import.read',
    ];

    public function run(): void
    {
        $this->syncCapabilities();
        $this->syncSystemRole(
            'system.access-admin',
            'مسؤول صلاحيات النظام',
            'System access administrator',
            $this->accessAdminCapabilities(),
        );
        $this->syncSystemRole(
            'system.security-auditor',
            'مدقق أمن النظام',
            'System security auditor',
            $this->securityAuditorCapabilities(),
        );
    }

    private function syncCapabilities(): void
    {
        $now = now();
        $rows = array_map(static function (string $capabilityCode) use ($now): array {
            $action = substr($capabilityCode, (int) strrpos($capabilityCode, '.') + 1);

            return [
                'id' => UuidV7::generate(),
                'module_code' => explode('.', $capabilityCode, 2)[0],
                'capability_code' => $capabilityCode,
                'action' => $action,
                'sensitivity' => in_array($action, self::SENSITIVE_ACTIONS, true)
                    || str_starts_with($capabilityCode, 'identity.account.')
                    || in_array($capabilityCode, self::SENSITIVE_CAPABILITIES, true) ? 'sensitive' : 'normal',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, CapabilityCatalog::all());

        DB::table('capabilities')->upsert(
            $rows,
            ['module_code', 'capability_code'],
            ['action', 'sensitivity', 'status', 'updated_at'],
        );
    }

    /** @param  list<string>  $capabilityCodes */
    private function syncSystemRole(string $code, string $nameAr, string $nameEn, array $capabilityCodes): void
    {
        $now = now();
        DB::table('roles')->insertOrIgnore([
            'id' => UuidV7::generate(),
            'code' => $code,
            'name_ar' => $nameAr,
            'name_en' => $nameEn,
            'role_type' => 'system',
            'status' => 'active',
            'is_system_role' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $roleId = DB::table('roles')->where('code', $code)->value('id');
        if (! is_string($roleId) || $capabilityCodes === []) {
            return;
        }

        $capabilityIds = DB::table('capabilities')
            ->whereIn('capability_code', $capabilityCodes)
            ->pluck('id');

        $rows = [];
        foreach ($capabilityIds as $capabilityId) {
            $rows[] = [
                'role_id' => $roleId,
                'capability_id' => (string) $capabilityId,
                'effect' => 'allow',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('role_capabilities')->insertOrIgnore($rows);
    }

    /** @return list<string> */
    private function accessAdminCapabilities(): array
    {
        return array_values(array_filter(
            CapabilityCatalog::all(),
            static fn (string $capabilityCode): bool => str_starts_with($capabilityCode, 'identity.account.')
                || str_starts_with($capabilityCode, 'authorization.')
                || (str_starts_with($capabilityCode, 'organization.')
                    && (str_ends_with($capabilityCode, '.read') || str_ends_with($capabilityCode, '.manage'))),
        ));
    }

    /** @return list<string> */
    private function securityAuditorCapabilities(): array
    {
        return array_values(array_filter(
            CapabilityCatalog::all(),
            static fn (string $capabilityCode): bool => str_starts_with($capabilityCode, 'authorization.')
                && str_ends_with($capabilityCode, '.read'),
        ));
    }
}
