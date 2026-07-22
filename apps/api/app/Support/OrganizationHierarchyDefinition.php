<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure data description of the four-layer healthcare cluster hierarchy.
 *
 * Why a separate file: keeps the org chart reviewable in one place and lets
 * the demo seeder iterate over a single source of truth instead of hard-coding
 * every name in the seeder body. No I/O, no Laravel facades — the seeder does
 * the writes.
 *
 * Layers (per the product brief):
 *   L1 — Cluster root (التجمع الصحي الثالث) with the Executive Director position.
 *   L2 — Five Departments (إدارات) reporting to the Executive Director.
 *   L3 — Sections and Units under the Projects Department, ending at the
 *        Follow-up Unit (وحدة المتابعة).
 *   L4 — Positions (managers + employees) inside every Section/Unit/Department,
 *        plus Persons and active primary assignments for every human slot.
 */
final class OrganizationHierarchyDefinition
{
    /**
     * @return array{
     *     cluster_code: string,
     *     cluster_name_ar: string,
     *     cluster_name_en: string,
     *     executive: array{
     *         position_code: string,
     *         title_ar: string,
     *         employee_number: string,
     *         display_name_ar: string,
     *         display_name_en: string
     *     },
     *     departments: list<array{
     *         code: string,
     *         key: string,
     *         name_ar: string,
     *         name_en: string,
     *         director_position_code: string,
     *         director_title_ar: string,
     *         director_employee_number: string,
     *         director_display_name_ar: string,
     *         director_display_name_en: string,
     *         children: list<array{
     *             code: string,
     *             type_code: string,
     *             key: string,
     *             name_ar: string,
     *             name_en: string,
     *             manager_position_code: string,
     *             manager_title_ar: string,
     *             manager_employee_number: string,
     *             manager_display_name_ar: string,
     *             manager_display_name_en: string,
     *             employees: list<array{
     *                 position_code: string,
     *                 title_ar: string,
     *                 employee_number: string,
     *                 display_name_ar: string,
     *                 display_name_en: string
     *             }>
     *         }>
     *     }>
     * }
     */
    public function describe(): array
    {
        return [
            'cluster_code' => 'R3-HEALTH-CLUSTER',
            'cluster_name_ar' => 'التجمع الصحي الثالث',
            'cluster_name_en' => 'Third Health Cluster',
            'executive' => [
                'position_code' => 'POS-EXEC',
                'title_ar' => 'المدير التنفيذي للتجمع الصحي الثالث',
                'employee_number' => 'EMP-EXEC-001',
                'display_name_ar' => 'د. خالد الغامدي',
                'display_name_en' => 'Dr. Khalid Al-Ghamdi',
            ],
            'departments' => [
                $this->department(
                    code: 'DEPT-HEALTH',
                    key: 'health',
                    name_ar: 'إدارة الخدمات الصحية',
                    name_en: 'Health Services Department',
                    director_employee_number: 'EMP-HLT-DIR',
                    director_display_name_ar: 'د. سلطان الشهري',
                    director_display_name_en: 'Dr. Sultan Al-Shehri',
                ),
                $this->department(
                    code: 'DEPT-HR',
                    key: 'hr',
                    name_ar: 'إدارة الموارد البشرية',
                    name_en: 'Human Resources Department',
                    director_employee_number: 'EMP-HRD-DIR',
                    director_display_name_ar: 'أمل الخالدي',
                    director_display_name_en: 'Amal Al-Khalidi',
                ),
                $this->department(
                    code: 'DEPT-FINANCE',
                    key: 'finance',
                    name_ar: 'إدارة المالية',
                    name_en: 'Finance Department',
                    director_employee_number: 'EMP-FIN-DIR',
                    director_display_name_ar: 'ماجد الحكمي',
                    director_display_name_en: 'Majed Al-Hakami',
                ),
                $this->department(
                    code: 'DEPT-IT',
                    key: 'it',
                    name_ar: 'إدارة تقنية المعلومات',
                    name_en: 'Information Technology Department',
                    director_employee_number: 'EMP-ITC-DIR',
                    director_display_name_ar: 'ريم العتيبي',
                    director_display_name_en: 'Reem Al-Otaibi',
                ),
                $this->projectsDepartmentWithFollowup(),
            ],
        ];
    }

    /** @return list<string> Every position_code the definition emits (used to detect prior seeding). */
    public function positionCodes(): array
    {
        $codes = [];
        $definition = $this->describe();
        $codes[] = $definition['executive']['position_code'];
        foreach ($definition['departments'] as $dept) {
            $codes[] = $dept['director_position_code'];
            foreach ($dept['children'] as $child) {
                $codes[] = $child['manager_position_code'];
                foreach ($child['employees'] as $employee) {
                    $codes[] = $employee['position_code'];
                }
            }
        }

        return $codes;
    }

    /** @return list<string> Every employee_number the definition emits (used for idempotency checks). */
    public function employeeNumbers(): array
    {
        $numbers = [];
        $definition = $this->describe();
        $numbers[] = $definition['executive']['employee_number'];
        foreach ($definition['departments'] as $dept) {
            $numbers[] = $dept['director_employee_number'];
            foreach ($dept['children'] as $child) {
                $numbers[] = $child['manager_employee_number'];
                foreach ($child['employees'] as $employee) {
                    $numbers[] = $employee['employee_number'];
                }
            }
        }

        return $numbers;
    }

    /**
     * @return array{
     *     code: string,
     *     key: string,
     *     name_ar: string,
     *     name_en: string,
     *     director_position_code: string,
     *     director_title_ar: string,
     *     director_employee_number: string,
     *     director_display_name_ar: string,
     *     director_display_name_en: string,
     *     children: list<array<string, mixed>>
     * }
     */
    private function department(
        string $code,
        string $key,
        string $name_ar,
        string $name_en,
        string $director_employee_number,
        string $director_display_name_ar,
        string $director_display_name_en,
    ): array {
        return [
            'code' => $code,
            'key' => $key,
            'name_ar' => $name_ar,
            'name_en' => $name_en,
            'director_position_code' => 'POS-DIR-'.strtoupper(str_replace('-', '_', $key)),
            'director_title_ar' => 'مدير '.$name_ar,
            'director_employee_number' => $director_employee_number,
            'director_display_name_ar' => $director_display_name_ar,
            'director_display_name_en' => $director_display_name_en,
            'children' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function projectsDepartmentWithFollowup(): array
    {
        $projects = $this->department(
            code: 'DEPT-PROJECTS',
            key: 'projects',
            name_ar: 'إدارة المشاريع',
            name_en: 'Projects Department',
            director_employee_number: 'EMP-PRJ-DIR',
            director_display_name_ar: 'د. نوال الرشيدي',
            director_display_name_en: 'Dr. Nawal Al-Rashidi',
        );

        $projects['children'] = [
            [
                'code' => 'SECT-PLANNING',
                'type_code' => 'section',
                'key' => 'planning',
                'name_ar' => 'قسم التخطيط',
                'name_en' => 'Planning Section',
                'manager_position_code' => 'POS-MGR-PLANNING',
                'manager_title_ar' => 'مدير قسم التخطيط',
                'manager_employee_number' => 'EMP-PLN-001',
                'manager_display_name_ar' => 'عبدالرحمن السهلي',
                'manager_display_name_en' => 'Abdulrahman Al-Sahli',
                'employees' => [],
            ],
            [
                'code' => 'SECT-EXEC',
                'type_code' => 'section',
                'key' => 'execution',
                'name_ar' => 'قسم التنفيذ',
                'name_en' => 'Execution Section',
                'manager_position_code' => 'POS-MGR-EXEC',
                'manager_title_ar' => 'مدير قسم التنفيذ',
                'manager_employee_number' => 'EMP-EXC-001',
                'manager_display_name_ar' => 'سارة الحربي',
                'manager_display_name_en' => 'Sarah Al-Harbi',
                'employees' => [],
            ],
            [
                'code' => 'SECT-QUALITY',
                'type_code' => 'section',
                'key' => 'quality',
                'name_ar' => 'قسم الجودة',
                'name_en' => 'Quality Section',
                'manager_position_code' => 'POS-MGR-QUALITY',
                'manager_title_ar' => 'مدير قسم الجودة',
                'manager_employee_number' => 'EMP-QLT-001',
                'manager_display_name_ar' => 'فهد القحطاني',
                'manager_display_name_en' => 'Fahad Al-Qahtani',
                'employees' => [],
            ],
            [
                'code' => 'UNIT-FOLLOWUP',
                'type_code' => 'unit',
                'key' => 'followup',
                'name_ar' => 'وحدة المتابعة',
                'name_en' => 'Follow-up Unit',
                'manager_position_code' => 'POS-MGR-FOLLOWUP',
                'manager_title_ar' => 'مدير وحدة المتابعة',
                'manager_employee_number' => 'EMP-FUP-000',
                'manager_display_name_ar' => 'منى الزهراني',
                'manager_display_name_en' => 'Mona Al-Zahrani',
                'employees' => [
                    [
                        'position_code' => 'POS-FUP-ANALYST',
                        'title_ar' => 'محلل بيانات المتابعة',
                        'employee_number' => 'EMP-FUP-001',
                        'display_name_ar' => 'أحمد المالكي',
                        'display_name_en' => 'Ahmed Al-Maliki',
                    ],
                    [
                        'position_code' => 'POS-FUP-MONITOR',
                        'title_ar' => 'أخصائي رصد التنفيذ',
                        'employee_number' => 'EMP-FUP-002',
                        'display_name_ar' => 'هند العمري',
                        'display_name_en' => 'Hend Al-Omari',
                    ],
                    [
                        'position_code' => 'POS-FUP-COORD',
                        'title_ar' => 'منسق المتابعة',
                        'employee_number' => 'EMP-FUP-003',
                        'display_name_ar' => 'نواف الشهري',
                        'display_name_en' => 'Nawaf Al-Shehri',
                    ],
                    [
                        'position_code' => 'POS-FUP-PMIS',
                        'title_ar' => 'أخصائي نظم معلومات المشاريع',
                        'employee_number' => 'EMP-FUP-004',
                        'display_name_ar' => 'لجين الدوسري',
                        'display_name_en' => 'Lujain Al-Dosari',
                    ],
                    [
                        'position_code' => 'POS-FUP-RISK',
                        'title_ar' => 'أخصائي مخاطر المشاريع',
                        'employee_number' => 'EMP-FUP-005',
                        'display_name_ar' => 'تركي العنزي',
                        'display_name_en' => 'Turki Al-Anzi',
                    ],
                ],
            ],
        ];

        return $projects;
    }
}
