<?php

namespace Modules\Authorization\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\CountOperationsOfficeMembers;
use Modules\Authorization\Domain\OfficeApprovalGuard;
use Modules\Authorization\Features\OperationsOffice\BootstrapOperationsOffice;
use Modules\Authorization\Features\OperationsOffice\OperationsOfficeRoleCatalog;
use Modules\Authorization\Infrastructure\Persistence\CountOperationsOfficeMembers as DatabaseCountOperationsOfficeMembers;
use Tests\TestCase;

final class OperationsOfficeBootstrapTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = '0197f0e0-0000-7000-8000-000000000001';

    private const CLUSTER = '0197f0e0-0000-7000-8000-000000000101';

    private const SECOND_MEMBER = '0197f0e0-0000-7000-8000-000000000002';

    public function test_count_returns_zero_before_bootstrap(): void
    {
        $this->app->make(OperationsOfficeRoleCatalog::class)->sync();

        $this->assertSame(0, $this->counter()->activeMembers());
    }

    public function test_count_returns_one_after_bootstrap_of_the_platform_owner(): void
    {
        $this->app->make(BootstrapOperationsOffice::class)->bootstrap(self::OWNER, self::CLUSTER);

        $this->assertSame(1, $this->counter()->activeMembers());
    }

    public function test_count_reflects_an_added_second_member(): void
    {
        $bootstrap = $this->app->make(BootstrapOperationsOffice::class);
        $bootstrap->bootstrap(self::OWNER, self::CLUSTER);

        $this->assignOfficeMember(self::SECOND_MEMBER);

        $this->assertSame(2, $this->counter()->activeMembers());
    }

    public function test_count_ignores_revoked_or_expired_assignments(): void
    {
        $this->app->make(BootstrapOperationsOffice::class)->bootstrap(self::OWNER, self::CLUSTER);

        $roleId = DB::table('roles')->where('code', OperationsOfficeRoleCatalog::OFFICE_MEMBER_ROLE)->value('id');
        $now = now()->utc();

        DB::table('role_assignments')->insert([
            'id' => Str::uuid7()->toString(),
            'user_id' => self::SECOND_MEMBER,
            'role_id' => $roleId,
            'scope_type' => 'cluster',
            'scope_id' => self::CLUSTER,
            'start_at' => $now->copy()->subDay(),
            'end_at' => $now->copy()->subHour(),
            'status' => 'revoked',
            'granted_by_user_id' => self::OWNER,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->assertSame(1, $this->counter()->activeMembers());
    }

    public function test_guard_denies_self_approval_when_no_members_are_active(): void
    {
        $outcome = OfficeApprovalGuard::canApproveAfterAuthoring(
            self::OWNER,
            self::OWNER,
            $this->zeroCounter(),
        );

        $this->assertFalse($outcome['allow']);
        $this->assertSame(OfficeApprovalGuard::CODE_OFFICE_EMPTY, $outcome['code']);
    }

    public function test_guard_allows_single_member_bootstrap_self_approval(): void
    {
        $this->app->make(BootstrapOperationsOffice::class)->bootstrap(self::OWNER, self::CLUSTER);

        $outcome = OfficeApprovalGuard::canApproveAfterAuthoring(
            self::OWNER,
            self::OWNER,
            $this->counter(),
        );

        $this->assertTrue($outcome['allow']);
        $this->assertSame(OfficeApprovalGuard::CODE_SINGLE_MEMBER_BOOTSTRAP_ALLOWED, $outcome['code']);
    }

    public function test_guard_forbids_self_approval_once_a_second_member_is_active(): void
    {
        $bootstrap = $this->app->make(BootstrapOperationsOffice::class);
        $bootstrap->bootstrap(self::OWNER, self::CLUSTER);

        $ownerOutcome = OfficeApprovalGuard::canApproveAfterAuthoring(
            self::OWNER,
            self::OWNER,
            $this->counter(),
        );
        $this->assertSame(
            OfficeApprovalGuard::CODE_SINGLE_MEMBER_BOOTSTRAP_ALLOWED,
            $ownerOutcome['code'],
        );

        $this->assignOfficeMember(self::SECOND_MEMBER);

        $deniedOutcome = OfficeApprovalGuard::canApproveAfterAuthoring(
            self::OWNER,
            self::OWNER,
            $this->counter(),
        );
        $this->assertFalse($deniedOutcome['allow']);
        $this->assertSame(OfficeApprovalGuard::CODE_SELF_APPROVAL_FORBIDDEN, $deniedOutcome['code']);
    }

    public function test_guard_allows_a_different_member_to_approve_regardless_of_count(): void
    {
        $bootstrap = $this->app->make(BootstrapOperationsOffice::class);
        $bootstrap->bootstrap(self::OWNER, self::CLUSTER);
        $this->assignOfficeMember(self::SECOND_MEMBER);

        $outcome = OfficeApprovalGuard::canApproveAfterAuthoring(
            self::OWNER,
            self::SECOND_MEMBER,
            $this->counter(),
        );

        $this->assertTrue($outcome['allow']);
        $this->assertSame(OfficeApprovalGuard::CODE_MULTI_MEMBER_ALLOWED, $outcome['code']);
    }

    public function test_guard_allows_a_different_member_to_approve_when_only_one_is_active(): void
    {
        $this->app->make(BootstrapOperationsOffice::class)->bootstrap(self::OWNER, self::CLUSTER);

        $outcome = OfficeApprovalGuard::canApproveAfterAuthoring(
            self::OWNER,
            self::SECOND_MEMBER,
            $this->counter(),
        );

        $this->assertTrue($outcome['allow']);
        $this->assertSame(OfficeApprovalGuard::CODE_MULTI_MEMBER_ALLOWED, $outcome['code']);
    }

    public function test_guard_returns_no_is_super_short_circuit(): void
    {
        $bootstrap = $this->app->make(BootstrapOperationsOffice::class);
        $bootstrap->bootstrap(self::OWNER, self::CLUSTER);
        $this->assignOfficeMember(self::SECOND_MEMBER);

        $outcome = OfficeApprovalGuard::canApproveAfterAuthoring(
            self::OWNER,
            self::OWNER,
            $this->counter(),
        );

        $this->assertFalse($outcome['allow']);
        $this->assertSame(OfficeApprovalGuard::CODE_SELF_APPROVAL_FORBIDDEN, $outcome['code']);
    }

    private function counter(): CountOperationsOfficeMembers
    {
        return $this->app->make(CountOperationsOfficeMembers::class);
    }

    private function zeroCounter(): CountOperationsOfficeMembers
    {
        return new DatabaseCountOperationsOfficeMembers;
    }

    private function assignOfficeMember(string $userId): void
    {
        $roleId = DB::table('roles')
            ->where('code', OperationsOfficeRoleCatalog::OFFICE_MEMBER_ROLE)
            ->value('id');

        $this->assertIsString($roleId, 'Office member role should be provisioned before assignment.');

        $now = now()->utc();

        DB::table('role_assignments')->insert([
            'id' => Str::uuid7()->toString(),
            'user_id' => $userId,
            'role_id' => $roleId,
            'scope_type' => 'cluster',
            'scope_id' => self::CLUSTER,
            'start_at' => $now->copy()->subMinute(),
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => self::OWNER,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
