<?php

namespace Tests\Feature;

use Database\Seeders\DevelopmentJourneyAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\CapabilityCatalog;
use Modules\Authorization\Features\OperationsOffice\OperationsOfficeRoleCatalog;
use Modules\Identity\Infrastructure\Security\PasswordHasher;
use Tests\TestCase;

final class LocalPlatformAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_seeder_creates_an_idempotent_cluster_scoped_platform_owner(): void
    {
        $seeder = app(DevelopmentJourneyAuthorizationSeeder::class);
        $seeder->run();
        $initialCredential = DB::table('credentials')
            ->where('user_id', DevelopmentJourneyAuthorizationSeeder::PLATFORM_ADMIN_ACCOUNT_ID)
            ->first();
        DB::table('users')
            ->where('id', DevelopmentJourneyAuthorizationSeeder::PLATFORM_ADMIN_ACCOUNT_ID)
            ->update(['is_admin' => true]);
        $seeder->run();

        $this->assertDatabaseHas('users', [
            'id' => DevelopmentJourneyAuthorizationSeeder::PLATFORM_ADMIN_ACCOUNT_ID,
            'username' => DevelopmentJourneyAuthorizationSeeder::PLATFORM_ADMIN_USERNAME,
            'display_name_ar' => 'مدير المنصة',
            'status' => 'active',
            'is_admin' => false,
        ]);
        $this->assertSame(1, DB::table('users')->where('username', DevelopmentJourneyAuthorizationSeeder::PLATFORM_ADMIN_USERNAME)->count());

        $credential = DB::table('credentials')
            ->where('user_id', DevelopmentJourneyAuthorizationSeeder::PLATFORM_ADMIN_ACCOUNT_ID)
            ->first();
        $this->assertNotNull($credential);
        $this->assertTrue(app(PasswordHasher::class)->check(
            DevelopmentJourneyAuthorizationSeeder::PLATFORM_ADMIN_PASSWORD,
            (string) $credential->password_hash,
        ));
        $this->assertSame($initialCredential->password_hash, $credential->password_hash);
        $this->assertSame((string) $initialCredential->password_changed_at, (string) $credential->password_changed_at);

        $this->assertDatabaseHas('assignments', [
            'person_id' => DevelopmentJourneyAuthorizationSeeder::PLATFORM_ADMIN_PERSON_ID,
            'is_primary' => true,
        ]);

        $clusterId = DB::table('clusters')->where('singleton_key', 1)->value('id');
        $roleId = DB::table('roles')->where('code', OperationsOfficeRoleCatalog::PLATFORM_OWNER_ROLE)->value('id');
        $this->assertDatabaseHas('role_assignments', [
            'user_id' => DevelopmentJourneyAuthorizationSeeder::PLATFORM_ADMIN_ACCOUNT_ID,
            'role_id' => $roleId,
            'scope_type' => 'cluster',
            'scope_id' => $clusterId,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('authorization_bootstrap', [
            'state' => 'complete',
            'completed_by_user_id' => DevelopmentJourneyAuthorizationSeeder::PLATFORM_ADMIN_ACCOUNT_ID,
        ]);

        $this->assertSame(
            count(CapabilityCatalog::all()),
            DB::table('role_capabilities')->where('role_id', $roleId)->where('effect', 'allow')->count(),
        );
    }
}
