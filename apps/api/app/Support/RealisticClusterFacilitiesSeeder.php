<?php

declare(strict_types=1);

namespace App\Support;

use Database\Seeders\DevelopmentJourneyAuthorizationSeeder;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Authorization\Contracts\CapabilityCatalog;
use Modules\Authorization\Domain\UuidV7;
use Modules\Identity\Infrastructure\Security\PasswordHasher;
use Modules\Organization\Domain\Facility;
use Modules\Organization\Features\Assignment\Handler\AssignmentHandler;
use Modules\Organization\Features\CreateFacility\Handler\CreateFacilityHandler;
use Modules\Organization\Features\OrganizationUnit\Handler\OrganizationUnitHandler;
use Modules\Organization\Features\Person\Handler\PersonHandler;
use Modules\Organization\Features\Position\Handler\PositionHandler;
use Modules\Organization\Http\OrganizationApi;

/**
 * Seeds two real facilities under the cluster — each with its own complete
 * hierarchy (executive director → departments → sections → staff) — and wires
 * login accounts that demonstrate the scope model:
 *   - the cluster executive (cluster scope) sees every facility, and
 *   - each facility director (facility scope) sees only their facility.
 *
 * Delegates every organization write to the owning module handlers so
 * path_cache/depth/events stay correct; login + role rows follow the pattern
 * used by {@see DevelopmentJourneyAuthorizationSeeder}.
 * Local/testing only.
 */
final class RealisticClusterFacilitiesSeeder
{
    private const PRINCIPAL_USER_ID = OrganizationHierarchyDemoSeeder::PRINCIPAL_USER_ID;

    private const ROLE_CLUSTER_ADMIN = 'demo.cluster-administrator';

    private const ROLE_FACILITY_OPERATOR = 'demo.facility-operator';

    /** Capability modules the demo login roles receive. */
    private const MODULES = [
        'work_record', 'work_definition', 'workflow', 'tasks', 'documents',
        'search', 'reporting', 'notifications', 'organization',
    ];

    public function __construct(
        private readonly OrganizationHierarchyDemoSeeder $hqSeeder,
        private readonly CreateFacilityHandler $facilities,
        private readonly OrganizationUnitHandler $units,
        private readonly PositionHandler $positions,
        private readonly PersonHandler $people,
        private readonly AssignmentHandler $assignments,
        private readonly PasswordHasher $hasher,
    ) {}

    /**
     * @return array{cluster_id: string, facilities: list<array<string, mixed>>, logins: list<array{username: string, password: string, scope: string}>}
     */
    public function seed(): array
    {
        if (! in_array(app()->environment(), ['local', 'testing'], true)) {
            throw new \LogicException('Realistic cluster seeding is local/testing only. Refusing to run in '.app()->environment().'.');
        }

        // 1) Cluster + its own full HQ hierarchy (executive + departments).
        $hq = $this->hqSeeder->seed(force: true);
        $clusterId = (string) $hq['cluster_id'];
        $correlation = UuidV7::generate();

        // 2) Two real facilities, each with a complete internal hierarchy.
        $facilitySummaries = [];
        foreach ($this->facilityDefinitions() as $definition) {
            $facilitySummaries[] = $this->buildFacility($clusterId, $definition, $correlation);
        }

        // 3) Login accounts demonstrating cluster-wide vs facility-only scope.
        $logins = $this->wireLogins($clusterId, $facilitySummaries);

        return [
            'cluster_id' => $clusterId,
            'facilities' => $facilitySummaries,
            'logins' => $logins,
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function buildFacility(string $clusterId, array $definition, string $correlation): array
    {
        $principal = ['user_id' => self::PRINCIPAL_USER_ID, 'facility_id' => OrganizationHierarchyDemoSeeder::FIXTURE_FACILITY_ID];

        $facility = $this->createFacilitySafe(
            $this->uuidV7FromKey('facility|'.$definition['code']),
            $clusterId,
            $definition['type'],
            $definition['code'],
            $definition['name_ar'],
            $definition['name_en'],
            $principal,
            $correlation,
        );
        $facilityId = (string) $facility['id'];

        // Executive office holds the facility director, parented to the facility.
        $execOffice = $this->createUnitSafe(
            $this->uuidV7FromKey('unit|'.$definition['code'].'|EXEC-OFFICE'),
            $clusterId, $facilityId, 'department', 'EXEC-OFFICE',
            'مكتب المدير التنفيذي', 'Executive Office', $principal, $correlation,
        );
        $directorPosition = $this->createPositionSafe(
            $this->uuidV7FromKey('position|'.$definition['code'].'|DIRECTOR'),
            $execOffice['id'], 'DIRECTOR', $definition['director']['title'], null,
            $principal, $correlation,
        );
        $directorPerson = $this->createPersonSafe(
            $definition['director']['emp'], $definition['director']['name_ar'], $definition['director']['name_en'],
            $principal, $correlation,
        );
        $this->createAssignmentSafe($directorPerson['id'], $directorPosition['id'], $principal, $correlation);

        $peopleCount = 1;
        $unitCount = 1;
        foreach ($definition['departments'] as $department) {
            $deptUnit = $this->createUnitSafe(
                $this->uuidV7FromKey('unit|'.$definition['code'].'|'.$department['code']),
                $clusterId, $facilityId, 'department', $department['code'],
                $department['name_ar'], $department['name_en'], $principal, $correlation,
            );
            $unitCount++;
            $managerPosition = $this->createPositionSafe(
                $this->uuidV7FromKey('position|'.$definition['code'].'|'.$department['code'].'|MGR'),
                $deptUnit['id'], 'MANAGER', $department['manager']['title'], $directorPosition['id'],
                $principal, $correlation,
            );
            $managerPerson = $this->createPersonSafe(
                $department['manager']['emp'], $department['manager']['name_ar'], $department['manager']['name_en'],
                $principal, $correlation,
            );
            $this->createAssignmentSafe($managerPerson['id'], $managerPosition['id'], $principal, $correlation);
            $peopleCount++;

            foreach ($department['sections'] ?? [] as $section) {
                $sectionUnit = $this->createUnitSafe(
                    $this->uuidV7FromKey('unit|'.$definition['code'].'|'.$department['code'].'|'.$section['code']),
                    $clusterId, $deptUnit['id'], 'section', $section['code'],
                    $section['name_ar'], $section['name_en'], $principal, $correlation,
                );
                $unitCount++;
                $headPosition = $this->createPositionSafe(
                    $this->uuidV7FromKey('position|'.$definition['code'].'|'.$section['code'].'|HEAD'),
                    $sectionUnit['id'], 'HEAD', $section['head']['title'], $managerPosition['id'],
                    $principal, $correlation,
                );
                $headPerson = $this->createPersonSafe(
                    $section['head']['emp'], $section['head']['name_ar'], $section['head']['name_en'],
                    $principal, $correlation,
                );
                $this->createAssignmentSafe($headPerson['id'], $headPosition['id'], $principal, $correlation);
                $peopleCount++;

                foreach ($section['staff'] ?? [] as $index => $staff) {
                    $staffPosition = $this->createPositionSafe(
                        $this->uuidV7FromKey('position|'.$definition['code'].'|'.$section['code'].'|S'.$index),
                        $sectionUnit['id'], 'STAFF-'.($index + 1), $staff['title'], $headPosition['id'],
                        $principal, $correlation,
                    );
                    $staffPerson = $this->createPersonSafe(
                        $staff['emp'], $staff['name_ar'], $staff['name_en'], $principal, $correlation,
                    );
                    $this->createAssignmentSafe($staffPerson['id'], $staffPosition['id'], $principal, $correlation);
                    $peopleCount++;
                }
            }
        }

        return [
            'id' => $facilityId,
            'code' => $definition['code'],
            'name_ar' => $definition['name_ar'],
            'director_person_id' => $directorPerson['id'],
            'director_employee_number' => $definition['director']['emp'],
            'units' => $unitCount,
            'people' => $peopleCount,
            'login_username' => $definition['login_username'],
        ];
    }

    // ---------------------------------------------------------------------
    // Login + authorization wiring
    // ---------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $facilities
     * @return list<array{username: string, password: string, scope: string}>
     */
    private function wireLogins(string $clusterId, array $facilities): array
    {
        $clusterRoleId = $this->ensureRole(self::ROLE_CLUSTER_ADMIN, 'مدير التجمع (رؤية شاملة)', 'Cluster administrator');
        $facilityRoleId = $this->ensureRole(self::ROLE_FACILITY_OPERATOR, 'مشغّل المنشأة', 'Facility operator');
        $this->attachCapabilities($clusterRoleId);
        $this->attachCapabilities($facilityRoleId);

        $logins = [];

        // Cluster executive → cluster scope → sees every facility.
        $executive = DB::table('people')->where('employee_number', 'EMP-EXEC-001')->first();
        if ($executive !== null) {
            $password = 'Tajammu#Sehi-2026!';
            $this->createLogin('tajammu.exec', $password, $executive);
            $accountId = (string) DB::table('users')->where('username', 'tajammu.exec')->value('id');
            $this->grantRole($accountId, $clusterRoleId, 'cluster', $clusterId);
            $logins[] = ['username' => 'tajammu.exec', 'password' => $password, 'scope' => 'cluster — يرى كل المنشآت'];
        }

        // Each facility director → facility scope → sees only their facility.
        foreach ($facilities as $facility) {
            $director = DB::table('people')->where('employee_number', $facility['director_employee_number'])->first();
            if ($director === null) {
                continue;
            }
            $password = 'Munsha#Sehi-2026!';
            $this->createLogin($facility['login_username'], $password, $director);
            $accountId = (string) DB::table('users')->where('username', $facility['login_username'])->value('id');
            $this->grantRole($accountId, $facilityRoleId, 'facility', (string) $facility['id']);
            $logins[] = ['username' => $facility['login_username'], 'password' => $password, 'scope' => 'facility — '.$facility['name_ar']];
        }

        return $logins;
    }

    private function ensureRole(string $code, string $nameAr, string $nameEn): string
    {
        $existing = DB::table('roles')->where('code', $code)->value('id');
        if (is_string($existing)) {
            return $existing;
        }
        $id = UuidV7::generate();
        DB::table('roles')->insert([
            'id' => $id,
            'code' => $code,
            'name_ar' => $nameAr,
            'name_en' => $nameEn,
            'role_type' => 'operational',
            'status' => 'active',
            'is_system_role' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function attachCapabilities(string $roleId): void
    {
        $codes = array_values(array_filter(
            CapabilityCatalog::all(),
            static fn (string $code): bool => in_array(explode('.', $code, 2)[0], self::MODULES, true),
        ));
        foreach ($codes as $code) {
            $capabilityId = DB::table('capabilities')->where('capability_code', $code)->value('id');
            if (! is_string($capabilityId)) {
                continue;
            }
            DB::table('role_capabilities')->insertOrIgnore([
                'role_id' => $roleId,
                'capability_id' => $capabilityId,
                'effect' => 'allow',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function createLogin(string $username, string $password, object $person): void
    {
        $now = now();
        $accountId = $this->uuidV7FromKey('account|'.$username);
        $personVersion = (int) ($person->person_version ?? 1);

        DB::table('users')->insertOrIgnore([
            'id' => $accountId,
            'username' => $username,
            'person_id' => $person->id,
            'person_version' => $personVersion,
            'display_name_ar' => $person->display_name_ar,
            'display_name_en' => $person->display_name_en,
            'status' => 'active',
            'must_change_password' => false,
            'password_version' => 1,
            'failed_login_count' => 0,
            'locked_until' => null,
            'lock_version' => 1,
            'is_admin' => false,
            'lockout_level' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('identity_person_account_claims')->insertOrIgnore([
            'person_id' => $person->id,
            'account_id' => $accountId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $hash = $this->hasher->hash($password);
        DB::table('credentials')->updateOrInsert(
            ['user_id' => $accountId],
            [
                'id' => DB::table('credentials')->where('user_id', $accountId)->value('id') ?? UuidV7::generate(),
                'password_hash' => $hash,
                'hash_algorithm' => $this->hasher->algorithm(),
                'password_changed_at' => $now,
                'policy_version' => 'identity-password-v1',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
        DB::table('identity_password_history')->updateOrInsert(
            ['user_id' => $accountId, 'password_version' => 1],
            [
                'password_hash' => $hash,
                'hash_algorithm' => $this->hasher->algorithm(),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    private function grantRole(string $userId, string $roleId, string $scopeType, string $scopeId): void
    {
        $exists = DB::table('role_assignments')
            ->where('user_id', $userId)->where('role_id', $roleId)
            ->where('scope_id', $scopeId)->where('status', 'active')->exists();
        if ($exists) {
            return;
        }
        DB::table('role_assignments')->insert([
            'id' => UuidV7::generate(),
            'user_id' => $userId,
            'role_id' => $roleId,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => self::PRINCIPAL_USER_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------------
    // Safe organization writes (delegate to module handlers, idempotent)
    // ---------------------------------------------------------------------

    /**
     * @param  array{user_id: string, facility_id: string}  $principal
     * @return array<string, mixed>
     */
    private function createFacilitySafe(string $id, string $clusterId, string $typeCode, string $code, string $nameAr, string $nameEn, array $principal, string $correlation): array
    {
        $facility = Facility::create($id, $clusterId, $typeCode, $code, $nameAr, $nameEn);
        try {
            $result = $this->facilities->persist(
                $facility,
                OrganizationApi::cloudEvent(
                    'com.cluster.organization.facilitycreated.v1',
                    '/organization/facilities/'.$id,
                    $correlation, $clusterId, 'facility', $facility->toArray(), $principal,
                ),
                $this->idempotency('demo.facility.'.$code, $facility->toArray()),
            );

            return $result['facility'];
        } catch (DomainException $e) {
            if ($e->getMessage() !== 'facility_already_exists') {
                throw $e;
            }

            return (array) DB::table('facilities')->where('cluster_id', $clusterId)->where('code', $code)->first();
        }
    }

    /**
     * @param  array{user_id: string, facility_id: string}  $principal
     * @return array<string, mixed>
     */
    private function createUnitSafe(string $unitId, string $clusterId, string $parentId, string $typeCode, string $code, string $nameAr, string $nameEn, array $principal, string $correlation): array
    {
        $input = [
            'cluster_id' => $clusterId,
            'parent_id' => $parentId,
            'type_code' => $typeCode,
            'code' => $code,
            'name' => $nameAr,
            'name_en' => $nameEn,
        ];
        try {
            return $this->units->create(
                $unitId, $input, $this->idempotency('demo.unit.'.$unitId, $input),
                fn (array $data): array => OrganizationApi::cloudEvent(
                    'com.cluster.organization.organizationunitcreated.v1',
                    '/organization/units/'.$data['id'],
                    $correlation, $clusterId, 'organization_unit', $data, $principal,
                ),
            )['unit'];
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
    private function createPositionSafe(string $positionId, string $unitId, string $code, string $title, ?string $managerPositionId, array $principal, string $correlation): array
    {
        $input = [
            'organization_unit_id' => $unitId,
            'code' => $code,
            'title' => $title,
            'manager_position_id' => $managerPositionId,
        ];
        try {
            return $this->positions->create(
                $positionId, $input, $this->idempotency('demo.position.'.$positionId, $input),
                fn (array $data, string $clusterId): array => OrganizationApi::cloudEvent(
                    'com.cluster.organization.positioncreated.v1',
                    '/organization/positions/'.$data['id'],
                    $correlation, $clusterId, 'position', $data, $principal,
                ),
            )['position'];
        } catch (DomainException $e) {
            if ($e->getMessage() !== 'position_already_exists') {
                throw $e;
            }

            return (array) DB::table('positions')->where('id', $positionId)->first();
        }
    }

    /**
     * @param  array{user_id: string, facility_id: string}  $principal
     * @return array<string, mixed>
     */
    private function createPersonSafe(string $employeeNumber, string $nameAr, string $nameEn, array $principal, string $correlation): array
    {
        $input = [
            'employee_number' => $employeeNumber,
            'display_name_ar' => $nameAr,
            'display_name_en' => $nameEn,
            'status' => 'active',
        ];
        try {
            return $this->people->create(
                $this->uuidV7FromKey('person|'.$employeeNumber), $input,
                $this->idempotency('demo.person.'.$employeeNumber, $input),
                fn (array $data): array => [
                    OrganizationApi::cloudEventData(
                        'com.cluster.organization.personregistered.v1',
                        '/organization/people/'.$data['id'],
                        $correlation, OrganizationHierarchyDemoSeeder::FIXTURE_FACILITY_ID, $principal,
                        ['person' => $data], 'confidential',
                    ),
                ],
            )['person'];
        } catch (DomainException $e) {
            if ($e->getMessage() !== 'person_already_exists') {
                throw $e;
            }

            return (array) DB::table('people')->where('employee_number', $employeeNumber)->first();
        }
    }

    /**
     * @param  array{user_id: string, facility_id: string}  $principal
     */
    private function createAssignmentSafe(string $personId, string $positionId, array $principal, string $correlation): void
    {
        $input = [
            'person_id' => $personId,
            'position_id' => $positionId,
            'start_at' => '2026-01-01T00:00:00.000Z',
            'is_primary' => true,
        ];
        try {
            $this->assignments->create(
                $this->uuidV7FromKey('assignment|'.$personId.'|'.$positionId), $input,
                $this->idempotency('demo.assignment.'.$personId.'.'.$positionId, $input),
                fn (array $data, string $clusterId): array => OrganizationApi::cloudEvent(
                    'com.cluster.organization.assignmentstarted.v1',
                    '/organization/assignments/'.$data['id'],
                    $correlation, $clusterId, 'assignment', $data, $principal,
                ),
            );
        } catch (InvalidArgumentException|DomainException $e) {
            $exists = DB::table('assignments')->where('person_id', $personId)->where('position_id', $positionId)->exists();
            if (! $exists) {
                throw $e;
            }
        }
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function uuidV7FromKey(string $key): string
    {
        $hex = substr(hash('sha256', 'cluster.realistic.seeder.v1|'.$key), 0, 32);
        $group3 = '7'.substr($hex, 12, 3);
        $variantHigh = dechex(0x8 | (hexdec(substr($hex, 16, 1)) & 0x3));

        return strtolower(sprintf(
            '%s-%s-%s-%s%s-%s',
            substr($hex, 0, 8), substr($hex, 8, 4), $group3, $variantHigh, substr($hex, 17, 3), substr($hex, 20, 12),
        ));
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

    /** @return list<array<string, mixed>> */
    private function facilityDefinitions(): array
    {
        return [
            [
                'code' => 'ERADAH', 'type' => 'hospital',
                'name_ar' => 'مجمع إرادة والصحة النفسية', 'name_en' => 'Eradah Complex for Mental Health',
                'login_username' => 'eradah.dir',
                'director' => ['emp' => 'ERD-DIR', 'title' => 'المدير التنفيذي لمجمع إرادة والصحة النفسية', 'name_ar' => 'د. فيصل الدوسري', 'name_en' => 'Dr. Faisal Al-Dosari'],
                'departments' => [
                    [
                        'code' => 'DEPT-PSYCH', 'name_ar' => 'إدارة الطب النفسي', 'name_en' => 'Psychiatry Department',
                        'manager' => ['emp' => 'ERD-PSY-MGR', 'title' => 'مدير إدارة الطب النفسي', 'name_ar' => 'د. عبير القرني', 'name_en' => 'Dr. Abeer Al-Qarni'],
                        'sections' => [
                            [
                                'code' => 'SEC-OPD', 'name_ar' => 'قسم العيادات النفسية الخارجية', 'name_en' => 'Outpatient Psychiatry',
                                'head' => ['emp' => 'ERD-OPD-HEAD', 'title' => 'رئيس قسم العيادات النفسية', 'name_ar' => 'د. ماجد السبيعي', 'name_en' => 'Dr. Majed Al-Subaie'],
                                'staff' => [
                                    ['emp' => 'ERD-OPD-S1', 'title' => 'أخصائي نفسي', 'name_ar' => 'روان الحربي', 'name_en' => 'Rawan Al-Harbi'],
                                    ['emp' => 'ERD-OPD-S2', 'title' => 'أخصائي اجتماعي', 'name_ar' => 'خالد العتيبي', 'name_en' => 'Khalid Al-Otaibi'],
                                ],
                            ],
                            [
                                'code' => 'SEC-IPD', 'name_ar' => 'قسم التنويم النفسي', 'name_en' => 'Inpatient Psychiatry',
                                'head' => ['emp' => 'ERD-IPD-HEAD', 'title' => 'رئيس قسم التنويم', 'name_ar' => 'د. نورة الشمري', 'name_en' => 'Dr. Noura Al-Shammari'],
                                'staff' => [
                                    ['emp' => 'ERD-IPD-S1', 'title' => 'ممرض نفسي', 'name_ar' => 'سعد الغامدي', 'name_en' => 'Saad Al-Ghamdi'],
                                ],
                            ],
                        ],
                    ],
                    [
                        'code' => 'DEPT-NURS', 'name_ar' => 'إدارة التمريض', 'name_en' => 'Nursing Department',
                        'manager' => ['emp' => 'ERD-NUR-MGR', 'title' => 'مديرة التمريض', 'name_ar' => 'هيفاء الزهراني', 'name_en' => 'Haifa Al-Zahrani'],
                        'sections' => [],
                    ],
                    [
                        'code' => 'DEPT-ADMIN', 'name_ar' => 'الشؤون الإدارية والمالية', 'name_en' => 'Administrative & Financial Affairs',
                        'manager' => ['emp' => 'ERD-ADM-MGR', 'title' => 'مدير الشؤون الإدارية والمالية', 'name_ar' => 'بندر المطيري', 'name_en' => 'Bandar Al-Mutairi'],
                        'sections' => [],
                    ],
                ],
            ],
            [
                'code' => 'SHAQRA', 'type' => 'hospital',
                'name_ar' => 'مستشفى شقراء العام', 'name_en' => 'Shaqra General Hospital',
                'login_username' => 'shaqra.dir',
                'director' => ['emp' => 'SHQ-DIR', 'title' => 'المدير التنفيذي لمستشفى شقراء العام', 'name_ar' => 'د. إبراهيم الراجحي', 'name_en' => 'Dr. Ibrahim Al-Rajhi'],
                'departments' => [
                    [
                        'code' => 'DEPT-MED', 'name_ar' => 'إدارة الشؤون الطبية', 'name_en' => 'Medical Affairs Department',
                        'manager' => ['emp' => 'SHQ-MED-MGR', 'title' => 'مدير الشؤون الطبية', 'name_ar' => 'د. سلمان الحارثي', 'name_en' => 'Dr. Salman Al-Harthi'],
                        'sections' => [
                            [
                                'code' => 'SEC-ER', 'name_ar' => 'قسم الطوارئ', 'name_en' => 'Emergency Department',
                                'head' => ['emp' => 'SHQ-ER-HEAD', 'title' => 'رئيس قسم الطوارئ', 'name_ar' => 'د. ريما الفهد', 'name_en' => 'Dr. Rima Al-Fahd'],
                                'staff' => [
                                    ['emp' => 'SHQ-ER-S1', 'title' => 'طبيب طوارئ', 'name_ar' => 'يوسف القحطاني', 'name_en' => 'Yousef Al-Qahtani'],
                                    ['emp' => 'SHQ-ER-S2', 'title' => 'فني إسعاف', 'name_ar' => 'تركي البقمي', 'name_en' => 'Turki Al-Buqami'],
                                ],
                            ],
                            [
                                'code' => 'SEC-CLINIC', 'name_ar' => 'قسم العيادات الخارجية', 'name_en' => 'Outpatient Clinics',
                                'head' => ['emp' => 'SHQ-CLI-HEAD', 'title' => 'رئيس العيادات الخارجية', 'name_ar' => 'د. لمياء الدوسري', 'name_en' => 'Dr. Lamia Al-Dosari'],
                                'staff' => [
                                    ['emp' => 'SHQ-CLI-S1', 'title' => 'طبيب عام', 'name_ar' => 'فهد الرشيدي', 'name_en' => 'Fahad Al-Rashidi'],
                                ],
                            ],
                        ],
                    ],
                    [
                        'code' => 'DEPT-NURS', 'name_ar' => 'إدارة التمريض', 'name_en' => 'Nursing Department',
                        'manager' => ['emp' => 'SHQ-NUR-MGR', 'title' => 'مديرة التمريض', 'name_ar' => 'منال العنزي', 'name_en' => 'Manal Al-Anzi'],
                        'sections' => [],
                    ],
                    [
                        'code' => 'DEPT-ADMIN', 'name_ar' => 'الشؤون الإدارية والمالية', 'name_en' => 'Administrative & Financial Affairs',
                        'manager' => ['emp' => 'SHQ-ADM-MGR', 'title' => 'مدير الشؤون الإدارية والمالية', 'name_ar' => 'عمر الشهراني', 'name_en' => 'Omar Al-Shahrani'],
                        'sections' => [],
                    ],
                ],
            ],
        ];
    }
}
