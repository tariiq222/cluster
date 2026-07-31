<?php

declare(strict_types=1);

namespace App\Support;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Organization\Domain\Cluster;
use Modules\Organization\Features\Assignment\Handler\AssignmentHandler;
use Modules\Organization\Features\CreateCluster\Handler\CreateClusterHandler;
use Modules\Organization\Features\OrganizationUnit\Handler\OrganizationUnitHandler;
use Modules\Organization\Features\Person\Handler\PersonHandler;
use Modules\Organization\Features\Position\Handler\PositionHandler;
use Modules\Organization\Http\OrganizationApi;
use Throwable;

/**
 * Demo seeder that materialises the four-layer hierarchy described by
 * {@see OrganizationHierarchyDefinition} using the application's own
 * organization handlers, mirroring the W12 E2E fixture pattern.
 *
 * Differences from W12E2E:
 *  - Idempotent: detects whether our executive position already exists and
 *    short-circuits before any write.
 *  - Local environment friendly: not gated to APP_ENV=testing.
 *  - Stable UUIDv7 ids derived from employee_number + position_code so that
 *    repeat runs map to the same rows (the DB unique constraints make this safe
 *    to re-run).
 *
 * Delegates writes to owning module handlers — never inserts module tables
 * directly.
 */
final class OrganizationHierarchyDemoSeeder
{
    /**
     * Principal reused for idempotency + outbox metadata. The admin fixture
     * account id is the bootstrap identity admin and is owned by the Identity
     * module; it is safe for the Organization module to reference as the actor.
     */
    public const PRINCIPAL_USER_ID = '018f6f7d-0c00-7000-8000-000000000021';

    /** Stable facility id used by the Development Journey fixtures. */
    public const FIXTURE_FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000011';

    public function __construct(
        private readonly OrganizationHierarchyDefinition $definition,
        private readonly CreateClusterHandler $clusters,
        private readonly OrganizationUnitHandler $units,
        private readonly PositionHandler $positions,
        private readonly PersonHandler $people,
        private readonly AssignmentHandler $assignments,
    ) {}

    /**
     * @return array{
     *     seeded: bool,
     *     reason: string,
     *     cluster_id: ?string,
     *     departments: list<string>,
     *     sections: list<string>,
     *     units: list<string>,
     *     positions: list<string>,
     *     people: list<string>,
     *     assignments: list<string>
     * }
     */
    public function seed(bool $force = false): array
    {
        if (! in_array(app()->environment(), ['local', 'testing'], true)) {
            throw new \LogicException(
                'Organization hierarchy demo seeding is limited to local/testing. Refusing to run in '.app()->environment().'.',
            );
        }

        $definition = $this->definition->describe();

        if (! $force && $this->isAlreadySeeded()) {
            $existingCluster = $this->resolveCluster();

            return [
                'seeded' => false,
                'reason' => 'demo hierarchy already present (pass force=true to recreate)',
                'cluster_id' => $existingCluster['id'] ?? null,
                'departments' => [],
                'sections' => [],
                'units' => [],
                'positions' => [],
                'people' => [],
                'assignments' => [],
            ];
        }

        $principal = [
            'user_id' => self::PRINCIPAL_USER_ID,
            'facility_id' => self::FIXTURE_FACILITY_ID,
        ];
        $correlation = $this->uuidV7();
        $now = now();

        $cluster = $this->resolveOrCreateCluster($principal, $correlation);
        $clusterData = $cluster->toArray();

        // Executive Office sector: holds the singleton executive position. It is
        // a child of the cluster (parent_type=cluster) and is the conventional
        // anchor for top-of-cluster positions when no dedicated department unit
        // is appropriate.
        $executiveOffice = $this->createUnitSafe(
            unitId: $this->unitIdFor('sector', 'SECTOR-EXEC'),
            clusterId: $clusterData['id'],
            parentId: $clusterData['id'],
            typeCode: 'sector',
            code: 'SECTOR-EXEC',
            nameAr: 'المكتب التنفيذي للتجمع الصحي',
            nameEn: 'Cluster Executive Office',
            principal: $principal,
            correlation: $correlation,
        );
        $unitLedger['units'][] = $executiveOffice['id'];

        $unitLedger = ['departments' => [], 'sections' => [], 'units' => []];
        $positionLedger = [];
        $personLedger = [];
        $assignmentLedger = [];

        // Executive position belongs to the cluster via the dedicated sector unit.
        $executive = $definition['executive'];
        $executivePosition = $this->createPositionSafe(
            positionCode: $executive['position_code'],
            unitId: $executiveOffice['id'],
            code: 'EXEC-DIRECTOR',
            title: $executive['title_ar'],
            managerPositionId: null,
            clusterId: $clusterData['id'],
            principal: $principal,
            correlation: $correlation,
        );
        $positionLedger[] = $executivePosition['id'];

        $executivePerson = $this->createPersonSafe(
            employeeNumber: $executive['employee_number'],
            displayNameAr: $executive['display_name_ar'],
            displayNameEn: $executive['display_name_en'],
            principal: $principal,
            correlation: $correlation,
        );
        $personLedger[] = $executivePerson['id'];

        $assignmentLedger[] = $this->createAssignmentSafe(
            personId: $executivePerson['id'],
            positionId: $executivePosition['id'],
            principal: $principal,
            correlation: $correlation,
        )['id'];

        foreach ($definition['departments'] as $department) {
            $departmentUnit = $this->createUnitSafe(
                unitId: $this->unitIdFor('dept', $department['code']),
                clusterId: $clusterData['id'],
                parentId: $clusterData['id'],
                typeCode: 'department',
                code: $department['code'],
                nameAr: $department['name_ar'],
                nameEn: $department['name_en'],
                principal: $principal,
                correlation: $correlation,
            );
            $unitLedger['departments'][] = $departmentUnit['id'];

            $directorPosition = $this->createPositionSafe(
                positionCode: $department['director_position_code'],
                unitId: $departmentUnit['id'],
                code: $this->codeFor($department['director_position_code']),
                title: $department['director_title_ar'],
                managerPositionId: $executivePosition['id'],
                clusterId: $clusterData['id'],
                principal: $principal,
                correlation: $correlation,
            );
            $positionLedger[] = $directorPosition['id'];

            $directorPerson = $this->createPersonSafe(
                employeeNumber: $department['director_employee_number'],
                displayNameAr: $department['director_display_name_ar'],
                displayNameEn: $department['director_display_name_en'],
                principal: $principal,
                correlation: $correlation,
            );
            $personLedger[] = $directorPerson['id'];

            $assignmentLedger[] = $this->createAssignmentSafe(
                personId: $directorPerson['id'],
                positionId: $directorPosition['id'],
                principal: $principal,
                correlation: $correlation,
            )['id'];

            foreach ($department['children'] as $child) {
                $childUnit = $this->createUnitSafe(
                    unitId: $this->unitIdFor($child['type_code'], $child['code']),
                    clusterId: $clusterData['id'],
                    parentId: $departmentUnit['id'],
                    typeCode: $child['type_code'],
                    code: $child['code'],
                    nameAr: $child['name_ar'],
                    nameEn: $child['name_en'],
                    principal: $principal,
                    correlation: $correlation,
                );
                $bucket = $child['type_code'] === 'section' ? 'sections' : 'units';
                $unitLedger[$bucket][] = $childUnit['id'];

                $managerPosition = $this->createPositionSafe(
                    positionCode: $child['manager_position_code'],
                    unitId: $childUnit['id'],
                    code: $this->codeFor($child['manager_position_code']),
                    title: $child['manager_title_ar'],
                    managerPositionId: $directorPosition['id'],
                    clusterId: $clusterData['id'],
                    principal: $principal,
                    correlation: $correlation,
                );
                $positionLedger[] = $managerPosition['id'];

                $managerPerson = $this->createPersonSafe(
                    employeeNumber: $child['manager_employee_number'],
                    displayNameAr: $child['manager_display_name_ar'],
                    displayNameEn: $child['manager_display_name_en'],
                    principal: $principal,
                    correlation: $correlation,
                );
                $personLedger[] = $managerPerson['id'];

                $assignmentLedger[] = $this->createAssignmentSafe(
                    personId: $managerPerson['id'],
                    positionId: $managerPosition['id'],
                    principal: $principal,
                    correlation: $correlation,
                )['id'];

                foreach ($child['employees'] as $employee) {
                    $employeePosition = $this->createPositionSafe(
                        positionCode: $employee['position_code'],
                        unitId: $childUnit['id'],
                        code: $this->codeFor($employee['position_code']),
                        title: $employee['title_ar'],
                        managerPositionId: $managerPosition['id'],
                        clusterId: $clusterData['id'],
                        principal: $principal,
                        correlation: $correlation,
                    );
                    $positionLedger[] = $employeePosition['id'];

                    $employeePerson = $this->createPersonSafe(
                        employeeNumber: $employee['employee_number'],
                        displayNameAr: $employee['display_name_ar'],
                        displayNameEn: $employee['display_name_en'],
                        principal: $principal,
                        correlation: $correlation,
                    );
                    $personLedger[] = $employeePerson['id'];

                    $assignmentLedger[] = $this->createAssignmentSafe(
                        personId: $employeePerson['id'],
                        positionId: $employeePosition['id'],
                        principal: $principal,
                        correlation: $correlation,
                    )['id'];
                }
            }
        }

        return [
            'seeded' => true,
            'reason' => 'demo hierarchy created',
            'cluster_id' => $clusterData['id'],
            'departments' => $unitLedger['departments'],
            'sections' => $unitLedger['sections'],
            'units' => $unitLedger['units'],
            'positions' => $positionLedger,
            'people' => $personLedger,
            'assignments' => $assignmentLedger,
        ];
    }

    private function isAlreadySeeded(): bool
    {
        // The executive position lives under the executive-office sector; its
        // exact stored code is derived from the macro positionCode so the
        // detection stays robust if the macro changes. Checking for it tells
        // us whether the demo hierarchy has already been applied.
        $executiveCode = $this->codeFor('POS-EXEC');
        $row = DB::table('positions')->where('code', $executiveCode)->first();
        if ($row === null) {
            return false;
        }

        // Also check at least one follow-up employee position exists to catch
        // partial seeds from earlier broken runs.
        $followupCode = $this->codeFor('POS-FUP-ANALYST');
        $followup = DB::table('positions')->where('code', $followupCode)->first();

        return $followup !== null;
    }

    /** Stable position.code mapped to each macro positionCode used by the seeder. */
    private function codeFor(string $positionCode): string
    {
        $map = [
            'POS-EXEC' => 'EXEC-DIRECTOR',
            'POS-DIR-HEALTH' => 'DIR-HEALTH',
            'POS-DIR-HR' => 'DIR-HR',
            'POS-DIR-FINANCE' => 'DIR-FINANCE',
            'POS-DIR-IT' => 'DIR-IT',
            'POS-DIR-PROJECTS' => 'DIR-PROJECTS',
            'POS-MGR-PLANNING' => 'MGR-PLANNING',
            'POS-MGR-EXEC' => 'MGR-EXEC',
            'POS-MGR-QUALITY' => 'MGR-QUALITY',
            'POS-MGR-FOLLOWUP' => 'MGR-FOLLOWUP',
            'POS-FUP-ANALYST' => 'FUP-ANALYST',
            'POS-FUP-MONITOR' => 'FUP-MONITOR',
            'POS-FUP-COORD' => 'FUP-COORD',
            'POS-FUP-PMIS' => 'FUP-PMIS',
            'POS-FUP-RISK' => 'FUP-RISK',
        ];

        return $map[$positionCode] ?? $this->codeFromDefinition($positionCode);
    }

    /**
     * @param  array{user_id: string, facility_id: string}  $principal
     */
    private function resolveOrCreateCluster(array $principal, string $correlation): Cluster
    {
        $definition = $this->definition->describe();
        $existing = DB::table('clusters')->where('status', 'active')->first();
        if ($existing !== null) {
            return Cluster::create(
                (string) $existing->id,
                (string) $existing->code,
                (string) $existing->name_ar,
                $existing->name_en === null ? null : (string) $existing->name_en,
            );
        }

        $cluster = Cluster::create(
            $this->uuidV7(),
            $definition['cluster_code'],
            $definition['cluster_name_ar'],
            $definition['cluster_name_en'],
        );

        $this->clusters->persist(
            $cluster,
            OrganizationApi::cloudEvent(
                'com.cluster.organization.clustercreated.v1',
                '/organization/clusters/'.$cluster->id,
                $correlation,
                self::FIXTURE_FACILITY_ID,
                'cluster',
                $cluster->toArray(),
                $principal,
            ),
            $this->idempotency('demo.cluster', $cluster->toArray()),
        );

        return $cluster;
    }

    /**
     * @param  array{user_id: string, facility_id: string}  $principal
     * @return array<string, mixed>
     */
    private function createUnitSafe(
        string $unitId,
        string $clusterId,
        string $parentId,
        string $typeCode,
        string $code,
        string $nameAr,
        string $nameEn,
        array $principal,
        string $correlation,
    ): array {
        $input = [
            'cluster_id' => $clusterId,
            'parent_id' => $parentId,
            'type_code' => $typeCode,
            'code' => $code,
            'name' => $nameAr,
            'name_en' => $nameEn,
        ];

        try {
            $result = $this->units->create(
                $unitId,
                $input,
                $this->idempotency('demo.unit.'.$code, $input),
                fn (array $data): array => OrganizationApi::cloudEvent(
                    'com.cluster.organization.organizationunitcreated.v1',
                    '/organization/units/'.$data['id'],
                    $correlation,
                    $clusterId,
                    'organization_unit',
                    $data,
                    $principal,
                ),
            );

            return $result['unit'];
        } catch (DomainException $e) {
            if ($e->getMessage() !== 'organization_unit_already_exists') {
                throw $e;
            }

            return (array) DB::table('organization_units')->where('id', $unitId)->first();
        }
    }

    /**
     * @param  array{user_id: string, facility_id: string}  $principal
     * @return array<string, mixed>
     */
    private function createPositionSafe(
        string $positionCode,
        string $unitId,
        string $code,
        string $title,
        ?string $managerPositionId,
        string $clusterId,
        array $principal,
        string $correlation,
    ): array {
        $input = [
            'organization_unit_id' => $unitId,
            'code' => $code,
            'title' => $title,
            'manager_position_id' => $managerPositionId,
        ];

        try {
            $result = $this->positions->create(
                $this->positionIdFor($positionCode),
                $input,
                $this->idempotency('demo.position.'.$positionCode, $input),
                fn (array $data, string $positionClusterId): array => OrganizationApi::cloudEvent(
                    'com.cluster.organization.positioncreated.v1',
                    '/organization/positions/'.$data['id'],
                    $correlation,
                    $positionClusterId,
                    'position',
                    $data,
                    $principal,
                ),
            );

            return $result['position'];
        } catch (DomainException $e) {
            if ($e->getMessage() !== 'position_already_exists') {
                throw $e;
            }

            $row = DB::table('positions')->where('code', $code)->where('organization_unit_id', $unitId)->first();
            if ($row === null) {
                throw $e;
            }

            return (array) $row;
        }
    }

    /**
     * @param  array{user_id: string, facility_id: string}  $principal
     * @return array<string, mixed>
     */
    private function createPersonSafe(
        string $employeeNumber,
        string $displayNameAr,
        string $displayNameEn,
        array $principal,
        string $correlation,
    ): array {
        $input = [
            'employee_number' => $employeeNumber,
            'display_name_ar' => $displayNameAr,
            'display_name_en' => $displayNameEn,
            'status' => 'active',
        ];

        try {
            $result = $this->people->create(
                $this->personIdFor($employeeNumber),
                $input,
                $this->idempotency('demo.person.'.$employeeNumber, $input),
                fn (array $data): array => [
                    OrganizationApi::cloudEventData(
                        'com.cluster.organization.personregistered.v1',
                        '/organization/people/'.$data['id'],
                        $correlation,
                        self::FIXTURE_FACILITY_ID,
                        $principal,
                        ['person' => $data],
                        'confidential',
                    ),
                ],
            );

            return $result['person'];
        } catch (DomainException $e) {
            if ($e->getMessage() !== 'person_already_exists') {
                throw $e;
            }

            $row = DB::table('people')->where('employee_number', $employeeNumber)->first();
            if ($row === null) {
                throw $e;
            }

            return (array) $row;
        }
    }

    /**
     * @param  array{user_id: string, facility_id: string}  $principal
     * @return array<string, mixed>
     */
    private function createAssignmentSafe(
        string $personId,
        string $positionId,
        array $principal,
        string $correlation,
    ): array {
        $input = [
            'person_id' => $personId,
            'position_id' => $positionId,
            'start_at' => '2026-01-01T00:00:00.000Z',
            'is_primary' => true,
        ];
        $assignmentId = $this->assignmentIdFor($personId, $positionId);

        try {
            $result = $this->assignments->create(
                $assignmentId,
                $input,
                $this->idempotency('demo.assignment.'.$personId.'.'.$positionId, $input),
                fn (array $data, string $clusterId): array => OrganizationApi::cloudEvent(
                    'com.cluster.organization.assignmentstarted.v1',
                    '/organization/assignments/'.$data['id'],
                    $correlation,
                    $clusterId,
                    'assignment',
                    $data,
                    $principal,
                ),
            );

            return $result['assignment'];
        } catch (InvalidArgumentException|DomainException $e) {
            $existing = DB::table('assignments')
                ->where('person_id', $personId)
                ->where('position_id', $positionId)
                ->first();
            if ($existing === null) {
                throw $e;
            }

            return (array) $existing;
        }
    }

    /** Derive the Position.code (uppercase machine code) from a Position.positionCode definition key. */
    private function codeFromDefinition(string $positionCode): string
    {
        return substr(str_replace('POS-', '', $positionCode), 0, 64);
    }

    private function unitIdFor(string $type, string $code): string
    {
        return $this->uuidV7FromKey($type.'|'.$code);
    }

    private function positionIdFor(string $positionCode): string
    {
        return $this->uuidV7FromKey('position|'.$positionCode);
    }

    private function personIdFor(string $employeeNumber): string
    {
        return $this->uuidV7FromKey('person|'.$employeeNumber);
    }

    private function assignmentIdFor(string $personId, string $positionId): string
    {
        return $this->uuidV7FromKey('assignment|'.$personId.'|'.$positionId);
    }

    /**
     * Build a deterministic UUIDv7 identifier from a stable string key so that
     * repeat runs produce the same row id. The generated string passes the
     * strict /\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/
     * regex used by the Organization handlers (version 7 + variant 10xx).
     */
    private function uuidV7FromKey(string $key): string
    {
        // 32 hex chars exactly: 8-4-4-4-12 with version='7' and variant in 8..b.
        $hash = hash('sha256', 'cluster.demo.seeder.v1|'.$key);
        $hex = substr($hash, 0, 32);
        $group1 = substr($hex, 0, 8);
        $group2 = substr($hex, 8, 4);
        $group3 = '7'.substr($hex, 12, 3); // forced version 7
        $variantHigh = dechex(0x8 | (hexdec(substr($hex, 16, 1)) & 0x3)); // forced [8..b]
        $group4 = $variantHigh.substr($hex, 17, 3);
        $group5 = substr($hex, 20, 12);

        return strtolower(sprintf('%s-%s-%s-%s-%s', $group1, $group2, $group3, $group4, $group5));
    }

    private function uuidV7(): string
    {
        try {
            return Str::uuid7()->toString();
        } catch (Throwable) {
            $ts = dechex((int) (microtime(true) * 1000));
            $ts = str_pad(substr($ts, 0, 12), 12, '0', STR_PAD_LEFT);
            $timeHigh = substr($ts, 0, 8);
            $timeLow = substr($ts, 8, 4);
            $random = bin2hex(random_bytes(10));
            $ver = '7'.substr($random, 0, 3);
            $variantHigh = dechex(0x8 | (hexdec(substr($random, 3, 1)) & 0x3));
            $variantRest = substr($random, 4, 3);
            $node = substr($random, 6, 12);

            return strtolower(sprintf('%s-%s-%s-%s%s-%s', $timeHigh, $timeLow, $ver, $variantHigh, $variantRest, $node));
        }
    }

    /**
     * @param  array<string, mixed>  $semantics
     * @return array{principal_id: string, operation: string, key_hash: string, request_hash: string}
     */
    private function idempotency(string $operation, array $semantics): array
    {
        return [
            'principal_id' => self::PRINCIPAL_USER_ID,
            'operation' => $operation,
            'key_hash' => hash('sha256', $operation.'|'.self::PRINCIPAL_USER_ID),
            'request_hash' => hash('sha256', json_encode($semantics, JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveCluster(): ?array
    {
        $row = DB::table('clusters')->where('status', 'active')->first();

        return $row === null ? null : (array) $row;
    }
}
