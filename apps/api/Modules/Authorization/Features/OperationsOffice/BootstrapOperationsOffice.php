<?php

namespace Modules\Authorization\Features\OperationsOffice;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorization\Domain\UuidV7;
use RuntimeException;

final class BootstrapOperationsOffice
{
    public function __construct(private readonly OperationsOfficeRoleCatalog $catalog) {}

    /**
     * Assigns the first platform owner through ordinary cluster-scoped RBAC rows.
     * The caller supplies the trusted Identity user id and Organization cluster id;
     * Authorization never reads either module's tables.
     */
    public function bootstrap(string $ownerUserId, string $clusterId): void
    {
        UuidV7::assert($ownerUserId, 'Platform owner user id');
        UuidV7::assert($clusterId, 'Operations office cluster id');

        DB::transaction(function () use ($ownerUserId, $clusterId): void {
            $this->catalog->sync();
            $this->assign($ownerUserId, OperationsOfficeRoleCatalog::PLATFORM_OWNER_ROLE, $clusterId, $ownerUserId);
            $this->assign($ownerUserId, OperationsOfficeRoleCatalog::OFFICE_MEMBER_ROLE, $clusterId, $ownerUserId);
        });
    }

    private function assign(string $userId, string $roleCode, string $clusterId, string $grantorUserId): void
    {
        $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
        if (! is_string($roleId)) {
            throw new RuntimeException("Authorization role {$roleCode} is unavailable.");
        }

        $exists = DB::table('role_assignments')
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->where('scope_type', 'cluster')
            ->where('scope_id', $clusterId)
            ->where('status', 'active')
            ->where('start_at', '<=', now()->utc())
            ->where(static fn ($query) => $query->whereNull('end_at')->orWhere('end_at', '>', now()->utc()))
            ->exists();
        if ($exists) {
            return;
        }

        $now = now()->utc();
        DB::table('role_assignments')->insert([
            'id' => Str::uuid7()->toString(),
            'user_id' => $userId,
            'role_id' => $roleId,
            'scope_type' => 'cluster',
            'scope_id' => $clusterId,
            'start_at' => $now,
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => $grantorUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
