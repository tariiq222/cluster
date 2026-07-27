<?php

namespace Modules\Authorization\Tests;

use Mockery;
use Modules\Authorization\Adapter\AuthorizeIdentityManagementAdapter;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Authorization\Infrastructure\Persistence\ListActiveRoleSummariesForUser;
use Modules\Authorization\Infrastructure\Persistence\ListEffectiveCapabilitiesForUser;
use PHPUnit\Framework\TestCase;

class AuthorizeIdentityManagementAdapterTest extends TestCase
{
    public function test_account_decisions_do_not_treat_the_actor_facility_as_the_target_account_facility(): void
    {
        $principal = [
            'user_id' => '018f6f7d-0c00-7000-8000-000000000601',
            'facility_id' => '018f6f7d-0c00-7000-8000-000000000602',
        ];
        $access = Mockery::mock(DecideAccess::class);
        $access->shouldReceive('decide')
            ->twice()
            ->with(
                $principal,
                Mockery::on(static fn (string $capability): bool => in_array($capability, [
                    'identity.account.read',
                    'identity.account.manage',
                ], true)),
                Mockery::on(static fn (?RecordFacts $facts): bool => $facts?->ownerFacilityId === null
                    && $facts->resourceType === 'identity_account'),
            )
            ->andReturn(new AccessDecision(
                decision: 'deny',
                action: 'manage',
                resourceType: 'identity_account',
                reasonCodes: ['owner_facility_missing'],
                policyVersion: 'test-policy-v1',
                factsVersion: 'test-facts-v1',
                classification: 'confidential',
            ));
        $adapter = new AuthorizeIdentityManagementAdapter(
            $access,
            new ListActiveRoleSummariesForUser,
            new ListEffectiveCapabilitiesForUser,
        );

        $this->assertFalse($adapter->canReadAccounts($principal));
        $this->assertFalse($adapter->canManageAccounts($principal));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
