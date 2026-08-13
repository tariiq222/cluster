<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Features\OperationsOffice\OperationsOfficeRoleCatalog;

return new class extends Migration
{
    public function up(): void
    {
        (new OperationsOfficeRoleCatalog)->sync();
    }

    public function down(): void
    {
        $roleIds = DB::table('roles')
            ->where('code', OperationsOfficeRoleCatalog::PLATFORM_OWNER_ROLE)
            ->pluck('id');

        if ($roleIds->isEmpty()) {
            return;
        }

        DB::table('role_assignments')->whereIn('role_id', $roleIds)->delete();
        DB::table('role_capabilities')->whereIn('role_id', $roleIds)->delete();
        DB::table('roles')->whereIn('id', $roleIds)->delete();
    }
};
