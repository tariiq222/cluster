<?php

namespace Modules\Authorization\Features\OperationsOffice;

use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\CapabilityCatalog;
use Modules\Authorization\Domain\UuidV7;

final class OperationsOfficeRoleCatalog
{
    public const PLATFORM_OWNER_ROLE = 'platform_owner';

    public const OFFICE_MEMBER_ROLE = 'operations_office_member';

    /** @var list<string> */
    public const OFFICE_CAPABILITIES = [
        'workflow.author',
        'workflow.approve',
    ];

    public function sync(): void
    {
        $this->syncCapabilities();
        $this->syncRole(
            self::PLATFORM_OWNER_ROLE,
            'مالك المنصة',
            'Platform owner',
            CapabilityCatalog::all(),
        );
        $this->syncRole(
            self::OFFICE_MEMBER_ROLE,
            'عضو مكتب إدارة العمليات',
            'Operations office member',
            self::OFFICE_CAPABILITIES,
        );
    }

    private function syncCapabilities(): void
    {
        $now = now()->utc();
        $rows = array_map(static function (string $code) use ($now): array {
            $action = substr($code, (int) strrpos($code, '.') + 1);

            return [
                'id' => UuidV7::generate(),
                'module_code' => explode('.', $code, 2)[0],
                'capability_code' => $code,
                'action' => $action,
                'sensitivity' => CapabilityCatalog::sensitivity($code),
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

    /** @param list<string> $capabilityCodes */
    private function syncRole(string $code, string $nameAr, string $nameEn, array $capabilityCodes): void
    {
        $now = now()->utc();
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
        if (! is_string($roleId)) {
            return;
        }

        $rows = DB::table('capabilities')
            ->whereIn('capability_code', $capabilityCodes)
            ->pluck('id')
            ->map(static fn (mixed $capabilityId): array => [
                'role_id' => $roleId,
                'capability_id' => (string) $capabilityId,
                'effect' => 'allow',
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        if ($rows !== []) {
            DB::table('role_capabilities')->insertOrIgnore($rows);
        }
    }
}
