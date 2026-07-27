<?php

namespace Modules\Authorization\Tests;

use Mockery;
use Modules\Authorization\Adapter\AuthorizeIdentityManagementAdapter;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Authorization\Infrastructure\Persistence\ListActiveRoleSummariesForUser;
use Modules\Authorization\Infrastructure\Persistence\ListEffectiveCapabilitiesForUser;
use Modules\Organization\Contracts\GetDefaultClusterId;
use PHPUnit\Framework\TestCase;

class AuthorizeIdentityManagementAdapterTest extends TestCase
{
    public function test_account_read_and_manage_use_evaluate_only_and_carry_cluster_id(): void
    {
        $principal = [
            'user_id' => '018f6f7d-0c00-7000-8000-000000000601',
            'facility_id' => '018f6f7d-0c00-7000-8000-000000000602',
        ];

        $access = Mockery::mock(DecideAccess::class);
        $access->shouldReceive('evaluateOnly')
            ->twice()
            ->with(
                $principal,
                Mockery::on(static fn (string $capability): bool => in_array($capability, [
                    'identity.account.read',
                    'identity.account.manage',
                ], true)),
                Mockery::on(static fn (?RecordFacts $facts): bool => $facts?->ownerFacilityId === null
                    && $facts->resourceType === 'identity_account'
                    && $facts->clusterId === '018f6f7d-0c00-7000-8000-000000000701'),
            )
            ->andReturnUsing(static function (array $actor, string $capability, ?RecordFacts $facts): AccessDecision {
                return new AccessDecision(
                    decision: $capability === 'identity.account.read' ? 'allow' : 'deny',
                    action: $capability,
                    resourceType: $facts->resourceType,
                    reasonCodes: ['role_capability_allowed'],
                    policyVersion: 'test-policy-v1',
                    factsVersion: 'test-facts-v1',
                    classification: 'confidential',
                );
            });
        $access->shouldNotReceive('decide');

        $clusterId = new class implements GetDefaultClusterId
        {
            public function resolve(): string
            {
                return '018f6f7d-0c00-7000-8000-000000000701';
            }
        };

        $adapter = new AuthorizeIdentityManagementAdapter(
            $access,
            new ListActiveRoleSummariesForUser,
            new ListEffectiveCapabilitiesForUser,
            $clusterId,
        );

        $this->assertTrue($adapter->canReadAccounts($principal));
        $this->assertFalse($adapter->canManageAccounts($principal));
    }

    public function test_account_facts_carry_null_cluster_id_when_no_default_cluster_is_resolved(): void
    {
        $principal = [
            'user_id' => '018f6f7d-0c00-7000-8000-000000000601',
            'facility_id' => null,
        ];

        $access = Mockery::mock(DecideAccess::class);
        $access->shouldReceive('evaluateOnly')
            ->once()
            ->with(
                $principal,
                'identity.account.read',
                Mockery::on(static fn (?RecordFacts $facts): bool => $facts?->ownerFacilityId === null
                    && $facts->resourceType === 'identity_account'
                    && $facts->clusterId === null),
            )
            ->andReturn(new AccessDecision(
                decision: 'deny',
                action: 'identity.account.read',
                resourceType: 'identity_account',
                reasonCodes: ['active_role_assignment_not_found'],
                policyVersion: 'test-policy-v1',
                factsVersion: 'test-facts-v1',
                classification: 'confidential',
            ));

        $clusterId = new class implements GetDefaultClusterId
        {
            public function resolve(): ?string
            {
                return null;
            }
        };

        $adapter = new AuthorizeIdentityManagementAdapter(
            $access,
            new ListActiveRoleSummariesForUser,
            new ListEffectiveCapabilitiesForUser,
            $clusterId,
        );

        $this->assertFalse($adapter->canReadAccounts($principal));
    }

    public function test_activation_issue_still_persists_through_decide(): void
    {
        $principal = [
            'user_id' => '018f6f7d-0c00-7000-8000-000000000601',
        ];

        $access = Mockery::mock(DecideAccess::class);
        $access->shouldReceive('decide')
            ->once()
            ->with(
                $principal,
                'identity.account.manage',
                Mockery::on(static fn (?RecordFacts $facts): bool => $facts?->ownerFacilityId === null
                    && $facts->resourceType === 'identity_activation'
                    && $facts->clusterId === '018f6f7d-0c00-7000-8000-000000000701'),
            )
            ->andReturn(new AccessDecision(
                decision: 'allow',
                action: 'identity.account.manage',
                resourceType: 'identity_activation',
                reasonCodes: ['role_capability_allowed'],
                policyVersion: 'test-policy-v1',
                factsVersion: 'test-facts-v1',
                classification: 'confidential',
            ));
        $access->shouldNotReceive('evaluateOnly');

        $clusterId = new class implements GetDefaultClusterId
        {
            public function resolve(): string
            {
                return '018f6f7d-0c00-7000-8000-000000000701';
            }
        };

        $adapter = new AuthorizeIdentityManagementAdapter(
            $access,
            new ListActiveRoleSummariesForUser,
            new ListEffectiveCapabilitiesForUser,
            $clusterId,
        );

        $this->assertTrue($adapter->canIssueActivation($principal));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
