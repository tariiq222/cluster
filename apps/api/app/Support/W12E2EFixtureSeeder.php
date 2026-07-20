<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Identity\Domain\PasswordPolicy;
use Modules\Identity\Features\Activation\Handler\ActivationHandler;
use Modules\Identity\Features\UserAccount\Handler\UserAccountHandler;
use Modules\Identity\Http\IdentityApi;
use Modules\Organization\Domain\Cluster;
use Modules\Organization\Features\CreateCluster\Handler\CreateClusterHandler;
use Modules\Organization\Features\OrganizationUnit\Handler\OrganizationUnitHandler;
use Modules\Organization\Features\Person\Handler\PersonHandler;
use Modules\Organization\Features\Position\Handler\PositionHandler;
use Modules\Organization\Http\OrganizationApi;

/**
 * Test-runtime fixture composition only. It delegates writes to the owning
 * module application handlers and never joins module tables itself.
 */
final class W12E2EFixtureSeeder
{
    private const BOOTSTRAP_ADMIN_ID = '018f6f7d-0c00-7000-8000-000000000021';

    private const FIXTURE_FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000011';

    public function __construct(
        private readonly CreateClusterHandler $clusters,
        private readonly OrganizationUnitHandler $units,
        private readonly PositionHandler $positions,
        private readonly PersonHandler $people,
        private readonly UserAccountHandler $accounts,
        private readonly ActivationHandler $activations,
    ) {}

    /**
     * @return array{
     *     identity_username: string,
     *     identity_password: string,
     *     import_username: string,
     *     import_password: string,
     *     import_position_id: string,
     *     temporary_assignment_person_id: string,
     *     temporary_assignment_unit_id: string,
     *     temporary_assignment_capability: string
     * }
     */
    public function seed(): array
    {
        if (! app()->environment('testing')) {
            throw new \LogicException('W1.2 E2E fixtures are testing-only.');
        }

        $suffix = strtolower(bin2hex(random_bytes(6)));
        $correlationId = Str::uuid7()->toString();
        $principal = ['user_id' => self::BOOTSTRAP_ADMIN_ID, 'facility_id' => self::FIXTURE_FACILITY_ID];
        $cluster = Cluster::create(Str::uuid7()->toString(), 'E2E_'.strtoupper($suffix), 'تجمع اختبار W1.2', 'W1.2 E2E Cluster');
        $clusterData = $cluster->toArray();

        $this->clusters->persist(
            $cluster,
            OrganizationApi::cloudEvent(
                'com.cluster.organization.clustercreated.v1',
                '/organization/clusters/'.$cluster->id,
                $correlationId,
                self::FIXTURE_FACILITY_ID,
                'cluster',
                $clusterData,
                $principal,
            ),
            $this->idempotency('e2e.w1-2.cluster', $clusterData),
        );

        // The fixture user needs a real facility in their organization scope so
        // the trusted PrincipalContext resolver can resolve facility_ids. The
        // Organization unit handler resolves parent_type from parent_id, so we
        // insert the fixture facility directly and reference it from the unit.
        $facilityType = DB::table('facility_types')->where('code', 'center')->first();
        if (! $facilityType instanceof \stdClass) {
            throw new \LogicException('W1.2 E2E fixture requires the center facility type.');
        }
        DB::table('facilities')->insert([
            'id' => self::FIXTURE_FACILITY_ID,
            'cluster_id' => $cluster->id,
            'facility_type_id' => $facilityType->id,
            'code' => 'E2E-'.strtoupper($suffix),
            'name_ar' => 'منشأة اختبار W1.2',
            'name_en' => 'W1.2 E2E Facility',
            'status' => 'active',
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $unitInput = [
            'cluster_id' => $cluster->id,
            'parent_id' => self::FIXTURE_FACILITY_ID,
            'type_code' => 'department',
            'code' => 'E2E_'.strtoupper($suffix),
            'name' => 'وحدة اختبار W1.2',
            'name_en' => 'W1.2 E2E Unit',
        ];
        $unit = $this->units->create(
            Str::uuid7()->toString(),
            $unitInput,
            $this->idempotency('e2e.w1-2.unit', $unitInput),
            fn (array $data): array => OrganizationApi::cloudEvent(
                'com.cluster.organization.organizationunitcreated.v1',
                '/organization/units/'.$data['id'],
                $correlationId,
                $cluster->id,
                'organization_unit',
                $data,
                $principal,
            ),
        )['unit'];

        $positionInput = [
            'organization_unit_id' => $unit['id'],
            'code' => 'E2E_'.strtoupper($suffix),
            'title' => 'منصب اختبار W1.2',
            'manager_position_id' => null,
        ];
        $position = $this->positions->create(
            Str::uuid7()->toString(),
            $positionInput,
            $this->idempotency('e2e.w1-2.position', $positionInput),
            fn (array $data, string $clusterId): array => OrganizationApi::cloudEvent(
                'com.cluster.organization.positioncreated.v1',
                '/organization/positions/'.$data['id'],
                $correlationId,
                $clusterId,
                'position',
                $data,
                $principal,
            ),
        )['position'];

        $personInput = [
            'employee_number' => 'E2E-'.strtoupper($suffix),
            'display_name_ar' => 'مستخدم اختبار W1.2',
            'display_name_en' => 'W1.2 E2E User',
            'status' => 'active',
        ];
        $person = $this->people->create(
            Str::uuid7()->toString(),
            $personInput,
            $this->idempotency('e2e.w1-2.person', $personInput),
            fn (array $data): array => [
                OrganizationApi::cloudEventData(
                    'com.cluster.organization.personregistered.v1',
                    '/organization/people/'.$data['id'],
                    $correlationId,
                    self::FIXTURE_FACILITY_ID,
                    $principal,
                    ['person' => $data],
                    'confidential',
                ),
                OrganizationApi::cloudEventData(
                    'com.cluster.organization.identityprovisioningrequested.v1',
                    '/organization/people/'.$data['id'],
                    $correlationId,
                    self::FIXTURE_FACILITY_ID,
                    $principal,
                    [
                        'person_id' => $data['id'],
                        'person_version' => $data['person_version'],
                        'requested_account_status' => 'pending',
                    ],
                    'confidential',
                ),
            ],
        )['person'];

        $username = 'w12-e2e-'.substr($suffix, 0, 10);
        $accountInput = ['person_id' => $person['id'], 'person_version' => $person['person_version'], 'username' => $username];

        // Anchor the seeded person to a live organization position so the
        // trusted PrincipalContext resolver exposes a non-empty facility list.
        // Without an active assignment the W1.2 fixture session resolves to a
        // null facility and every session-only route returns 401.
        DB::table('assignments')->insert([
            'id' => Str::uuid7()->toString(),
            'person_id' => $person['id'],
            'position_id' => $position['id'],
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
            'is_primary' => true,
            'end_reason' => null,
            'ended_by_user_id' => null,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->accounts->create(
            self::BOOTSTRAP_ADMIN_ID,
            $accountInput,
            $this->idempotency('e2e.w1-2.account', $accountInput),
            fn (array $data): array => IdentityApi::cloudEvent(
                'com.cluster.identity.useraccountcreated.v1',
                '/identity/accounts/'.$data['id'],
                $correlationId,
                $principal,
                [
                    'account_id' => $data['id'],
                    'person_id' => $data['person_id'],
                    'person_version' => $data['person_version'],
                    'status' => $data['status'],
                    'action' => 'create',
                    'lock_version' => 1,
                ],
            ),
        );

        $password = $this->policySafePassword($username);
        $activation = $this->activations->issue(self::BOOTSTRAP_ADMIN_ID);
        $this->activations->activate($activation['token'], $password);

        $importUsername = 'w12-import-'.substr($suffix, 0, 8);
        $importPassword = $this->policySafePassword($importUsername);
        DB::table('identity_development_fixture_accounts')->where('id', self::BOOTSTRAP_ADMIN_ID)->update([
            'username' => $importUsername,
            'password_hash' => Hash::make($importPassword),
            'facility_id' => $unit['id'],
            'updated_at' => now(),
        ]);

        $this->grantJourneyOperatorRole(self::BOOTSTRAP_ADMIN_ID, (string) $unit['id']);
        $identityAccountId = DB::table('users')->where('username', $username)->value('id');
        if (is_string($identityAccountId)) {
            $this->grantJourneyOperatorRole($identityAccountId, self::FIXTURE_FACILITY_ID);
            $this->grantJourneyOperatorRole($identityAccountId, (string) $unit['id']);
        }

        return [
            'identity_username' => $username,
            'identity_password' => $password,
            'import_username' => $importUsername,
            'import_password' => $importPassword,
            'import_position_id' => $position['id'],
            'temporary_assignment_person_id' => $person['id'],
            'temporary_assignment_unit_id' => $unit['id'],
            'temporary_assignment_capability' => 'records.read',
        ];
    }

    /**
     * Grants the seeded journey operator role (DevelopmentJourneyAuthorizationSeeder)
     * to a user at one facility scope. No-op when the role is absent so the
     * fixture stays usable standalone.
     */
    /**
     * Random hex passwords occasionally trip the password policy (four
     * repeated characters or a username fragment); loop with the policy
     * itself as the oracle so seeding never flakes the full suite.
     */
    private function policySafePassword(string $username): string
    {
        $policy = app(PasswordPolicy::class);
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $candidate = 'W1!'.bin2hex(random_bytes(24));
            if ($policy->violations($candidate, $username) === []) {
                return $candidate;
            }
        }

        throw new \LogicException('W1.2 E2E fixture could not mint a policy-safe password.');
    }

    private function grantJourneyOperatorRole(string $userId, string $scopeId): void
    {
        $roleId = DB::table('roles')->where('code', 'journey.r1-operator')->value('id');
        if (! is_string($roleId)) {
            return;
        }
        $exists = DB::table('role_assignments')
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->where('scope_id', $scopeId)
            ->where('status', 'active')
            ->exists();
        if ($exists) {
            return;
        }
        DB::table('role_assignments')->insert([
            'id' => Str::uuid7()->toString(),
            'user_id' => $userId,
            'role_id' => $roleId,
            'scope_type' => 'facility',
            'scope_id' => $scopeId,
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => self::BOOTSTRAP_ADMIN_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $input @return array{principal_id: string, operation: string, key_hash: string, request_hash: string} */
    private function idempotency(string $operation, array $input): array
    {
        $key = Str::uuid7()->toString();

        return [
            'principal_id' => self::BOOTSTRAP_ADMIN_ID,
            'operation' => $operation,
            'key_hash' => hash('sha256', $key),
            'request_hash' => hash('sha256', json_encode($input, JSON_THROW_ON_ERROR)),
        ];
    }
}
