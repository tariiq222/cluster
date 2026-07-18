<?php

namespace Modules\Authorization\Tests;

use InvalidArgumentException;
use Modules\Authorization\Domain\Capability;
use Modules\Authorization\Domain\Delegation;
use Modules\Authorization\Domain\Role;
use Modules\Authorization\Domain\RoleAssignment;
use PHPUnit\Framework\TestCase;

class AuthorizationDataDomainTest extends TestCase
{
    private const ROLE_ID = '018f6f7d-0c00-7000-8000-000000000801';

    private const CAPABILITY_ID = '018f6f7d-0c00-7000-8000-000000000802';

    private const ASSIGNMENT_ID = '018f6f7d-0c00-7000-8000-000000000803';

    private const DELEGATION_ID = '018f6f7d-0c00-7000-8000-000000000804';

    private const USER_ID = '018f6f7d-0c00-7000-8000-000000000805';

    private const OTHER_USER_ID = '018f6f7d-0c00-7000-8000-000000000806';

    private const GRANTOR_ID = '018f6f7d-0c00-7000-8000-000000000807';

    private const SCOPE_ID = '018f6f7d-0c00-7000-8000-000000000808';

    public function test_role_and_capability_are_defined_with_uuidv7_identifiers_and_stable_codes(): void
    {
        $role = Role::define(
            self::ROLE_ID,
            'facility_manager',
            'مدير المنشأة',
            'Facility manager',
            'administrative',
        );
        $capability = Capability::define(
            self::CAPABILITY_ID,
            'organization',
            'organization.facility.manage',
            'manage',
            'sensitive',
        );

        $this->assertSame([
            'id' => self::ROLE_ID,
            'code' => 'facility_manager',
            'name_ar' => 'مدير المنشأة',
            'name_en' => 'Facility manager',
            'role_type' => 'administrative',
            'status' => 'active',
            'is_system_role' => false,
        ], $role->toArray());
        $this->assertSame([
            'id' => self::CAPABILITY_ID,
            'module_code' => 'organization',
            'capability_code' => 'organization.facility.manage',
            'action' => 'manage',
            'sensitivity' => 'sensitive',
            'status' => 'active',
        ], $capability->toArray());

        $this->assertInvalid(fn () => Role::define(
            '018F6F7D-0C00-7000-8000-000000000801',
            'facility_manager',
            'مدير المنشأة',
            null,
            'administrative',
        ));
        $this->assertInvalid(fn () => Capability::define(
            self::CAPABILITY_ID,
            'organization',
            'documents.initiate-upload',
            'manage',
            'normal',
        ));
    }

    public function test_role_assignment_supports_an_optional_uuid_scope_and_only_a_valid_utc_window(): void
    {
        $assignment = RoleAssignment::assign(
            self::ASSIGNMENT_ID,
            self::USER_ID,
            self::ROLE_ID,
            null,
            '2026-07-20T10:00:00.000Z',
            null,
            self::GRANTOR_ID,
        );

        $this->assertSame([
            'id' => self::ASSIGNMENT_ID,
            'user_id' => self::USER_ID,
            'role_id' => self::ROLE_ID,
            'scope_id' => null,
            'start_at' => '2026-07-20T10:00:00.000Z',
            'end_at' => null,
            'status' => 'pending',
            'granted_by_user_id' => self::GRANTOR_ID,
        ], $assignment->toArray());

        $scoped = RoleAssignment::assign(
            self::ASSIGNMENT_ID,
            self::USER_ID,
            self::ROLE_ID,
            self::SCOPE_ID,
            '2026-07-20T10:00:00.000Z',
            '2026-07-21T10:00:00.000Z',
            self::GRANTOR_ID,
        );
        $this->assertSame(self::SCOPE_ID, $scoped->scopeId);

        $this->assertInvalid(fn () => RoleAssignment::assign(
            self::ASSIGNMENT_ID,
            self::USER_ID,
            self::ROLE_ID,
            self::SCOPE_ID,
            '2026-07-20T10:00:00+03:00',
            '2026-07-21T10:00:00.000Z',
            self::GRANTOR_ID,
        ));
        $this->assertInvalid(fn () => RoleAssignment::assign(
            self::ASSIGNMENT_ID,
            self::USER_ID,
            self::ROLE_ID,
            self::SCOPE_ID,
            '2026-07-21T10:00:00.000Z',
            '2026-07-20T10:00:00.000Z',
            self::GRANTOR_ID,
        ));
    }

    public function test_delegation_rejects_self_delegation_and_requires_a_bounded_utc_module_capability_set(): void
    {
        $delegation = Delegation::create(
            self::DELEGATION_ID,
            self::USER_ID,
            self::OTHER_USER_ID,
            'organization',
            ['organization.facility.read', 'organization.facility.manage'],
            self::SCOPE_ID,
            '2026-07-20T10:00:00.000Z',
            '2026-07-21T10:00:00.000Z',
        );

        $this->assertSame([
            'id' => self::DELEGATION_ID,
            'delegator_user_id' => self::USER_ID,
            'delegate_user_id' => self::OTHER_USER_ID,
            'module_code' => 'organization',
            'scope_id' => self::SCOPE_ID,
            'start_at' => '2026-07-20T10:00:00.000Z',
            'end_at' => '2026-07-21T10:00:00.000Z',
            'status' => 'pending',
            'capability_codes' => ['organization.facility.read', 'organization.facility.manage'],
        ], $delegation->toArray());

        $this->assertInvalid(fn () => Delegation::create(
            self::DELEGATION_ID,
            self::USER_ID,
            self::USER_ID,
            'organization',
            ['organization.facility.read'],
            null,
            '2026-07-20T10:00:00.000Z',
            '2026-07-21T10:00:00.000Z',
        ));
        $this->assertInvalid(fn () => Delegation::create(
            self::DELEGATION_ID,
            self::USER_ID,
            self::OTHER_USER_ID,
            'organization',
            ['documents.initiate-upload'],
            null,
            '2026-07-20T10:00:00.000Z',
            '2026-07-21T10:00:00.000Z',
        ));
        $this->assertInvalid(fn () => Delegation::create(
            self::DELEGATION_ID,
            self::USER_ID,
            self::OTHER_USER_ID,
            'organization',
            ['organization.facility.read', 'organization.facility.read'],
            null,
            '2026-07-20T10:00:00.000Z',
            '2026-07-20T10:00:00.000Z',
        ));
    }

    private function assertInvalid(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException) {
            // Domain invariant rejection is the assertion target.
            $this->addToAssertionCount(1);
        }
    }
}
