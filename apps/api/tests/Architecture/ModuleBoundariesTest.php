<?php

namespace Tests\Architecture;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class ModuleBoundariesTest extends TestCase
{
    /**
     * Sole sanctioned path-scoped reverse-edge exception: the Authorization
     * bootstrap producer packet (`AUTHORIZATION-AUDIT-PRODUCER` token) is
     * allowed to depend on the Audit Contracts from exactly one file
     * (AuthorizationBootstrapState). The pair (sourceModule, targetModule)
     * MUST equal the token contract; any other import from this file, or any
     * other Authorization file importing Audit, is a contract violation.
     *
     * @var list<array{path: string, source: string, target: string, imports: list<string>, token: string, reason: string}>
     */
    private const CROSS_MODULE_IMPORT_EXCEPTIONS = [
        [
            'path' => 'Modules/Authorization/Infrastructure/Persistence/AuthorizationBootstrapState.php',
            'source' => 'Authorization',
            'target' => 'Audit',
            'imports' => ['Modules\\Audit\\Contracts\\AuditEventInput', 'Modules\\Audit\\Contracts\\RecordAuditEvent'],
            'token' => 'AUTHORIZATION-AUDIT-PRODUCER',
            'reason' => 'Bootstrap producer packet (M01 §9 Task 9) is the only sanctioned reverse edge from Authorization(rank2) to Audit(rank3) Contracts; Audit is implemented strictly as a producer consumer.',
        ],
    ];

    /** @var array<string, int> */
    private const MODULE_RANKS = [
        'Shared' => -1,
        'PlatformSettings' => 0,
        'Organization' => 0,
        'Identity' => 1,
        'Authorization' => 2,
        'Audit' => 3,
        'Workflow' => 4,
        'RecordsGovernance' => 4,
        'WorkDefinitions' => 5,
        'Documents' => 5,
        'Collaboration' => 6,
        'Tasks' => 7,
        'WorkRecords' => 8,
        'Strategy' => 8,
        'PortfolioProjects' => 9,
        'Risk' => 10,
        'Notifications' => 11,
        'Search' => 11,
        'Reporting' => 11,
        'Workspace' => 11,
    ];

    /**
     * Modules declared as canonical boundaries in docs/architecture/module-catalog.md
     * but NOT yet implemented as apps/api/Modules/<Name> directories. Each entry is
     * tracked as a planned module (R2/R3) so the architecture test cannot silently
     * pass when an unimplemented module gains a directory in error (the test would
     * treat it as the planned module and assert ownership correctly).
     *
     * @var list<string>
     */
    private const PLANNED_MODULES = [
        'RecordsGovernance',
        'Collaboration',
        'Workspace',
        'Strategy',
        'PortfolioProjects',
        'Risk',
    ];

    /**
     * W15 combined unrelated workflow review/runtime changes and was replaced
     * before registration by the narrower W16 decision-ledger migration plus
     * W17 approval columns. It remains as migration-history evidence only.
     *
     * @var list<string>
     */
    private const SUPERSEDED_MIGRATIONS = [
        'Modules/Workflow/Infrastructure/Persistence/Migrations/W15CreateWorkflowDecisionsTable.php',
    ];

    /** @var list<string> */
    private const LEGACY_MODULE_OUTBOX_TABLES = [
        'document_outbox_events',
        'platform_settings_outbox',
    ];

    /** @var array<string, string> */
    private const TABLE_OWNERS = [
        // PlatformSettings (rank 0)
        'platform_settings' => 'PlatformSettings',
        'platform_setting_versions' => 'PlatformSettings',
        'platform_alert_policies' => 'PlatformSettings',
        'business_calendars' => 'PlatformSettings',
        'business_calendar_weekdays' => 'PlatformSettings',
        'business_calendar_exceptions' => 'PlatformSettings',
        'platform_maintenance_windows' => 'PlatformSettings',
        'platform_settings_idempotency_keys' => 'PlatformSettings',
        'platform_operation_requests' => 'PlatformSettings',
        'platform_operation_snapshots' => 'PlatformSettings',
        'technical_log_archive_batches' => 'PlatformSettings',
        'technical_log_archive_manifests' => 'PlatformSettings',
        'technical_log_archive_restore_requests' => 'PlatformSettings',
        // Organization (rank 0)
        'clusters' => 'Organization',
        'facilities' => 'Organization',
        'facility_types' => 'Organization',
        'organization_units' => 'Organization',
        'unit_types' => 'Organization',
        'positions' => 'Organization',
        'job_titles' => 'Organization',
        'people' => 'Organization',
        'assignments' => 'Organization',
        'import_jobs' => 'Organization',
        'import_rows' => 'Organization',
        'organization_idempotency_keys' => 'Organization',
        'temporary_assignments' => 'Organization',
        'temporary_assignment_capabilities' => 'Organization',
        'supervisory_relationships' => 'Organization',
        'relationship_capabilities' => 'Organization',
        'organization_development_facilities' => 'Organization',
        // Identity (rank 1)
        // (identities table has no Schema::create migration; declared only in docs)
        'users' => 'Identity',
        'identity_sessions' => 'Identity',
        'identity_person_account_claims' => 'Identity',
        'identity_idempotency_keys' => 'Identity',
        'identity_inbox' => 'Identity',
        'identity_person_event_watermarks' => 'Identity',
        'identity_person_provisioning' => 'Identity',
        'identity_development_fixture_accounts' => 'Identity',
        'credentials' => 'Identity',
        'identity_password_history' => 'Identity',
        'identity_activation_tokens' => 'Identity',
        'identity_totp' => 'Identity',
        'identity_auth_attempt_ledgers' => 'Identity',
        // Authorization (rank 2)
        'roles' => 'Authorization',
        'capabilities' => 'Authorization',
        'role_capabilities' => 'Authorization',
        'role_assignments' => 'Authorization',
        'delegations' => 'Authorization',
        'delegation_capabilities' => 'Authorization',
        'explicit_denies' => 'Authorization',
        'access_decisions' => 'Authorization',
        'classification_policies' => 'Authorization',
        'field_access_templates' => 'Authorization',
        'authorization_bootstrap' => 'Authorization',
        'authorization_idempotency_keys' => 'Authorization',
        // Authorization retains ownership of access_decisions and sensitive_access_events. Audit (rank 3) is implemented and owns the four audit_* tables registered below; the historical debt note for sensitive_access_events was retired when Audit migrated.
        // Audit (rank 3, implemented 2026-07-27)
        'audit_events' => 'Audit',
        'audit_export_jobs' => 'Audit',
        'audit_integrity_checkpoints' => 'Audit',
        'audit_idempotency_keys' => 'Audit',
        'sensitive_access_events' => 'Authorization',
        // Workflow (rank 4)
        'workflow_definitions' => 'Workflow',
        'workflow_versions' => 'Workflow',
        'workflow_instances' => 'Workflow',
        'workflow_step_instances' => 'Workflow',
        'workflow_decisions' => 'Workflow',
        'workflow_idempotency_keys' => 'Workflow',
        // WorkDefinitions (rank 5)
        'work_definitions' => 'WorkDefinitions',
        'work_definition_versions' => 'WorkDefinitions',
        'work_definition_idempotency_keys' => 'WorkDefinitions',
        'work_definition_development_work_type_versions' => 'WorkDefinitions',
        // Documents (rank 5)
        'documents' => 'Documents',
        'document_versions' => 'Documents',
        'document_links' => 'Documents',
        'document_idempotency_keys' => 'Documents',
        'document_quarantines' => 'Documents',
        'document_storage_objects' => 'Documents',
        'document_upload_intents' => 'Documents',
        'document_restriction_facts' => 'Documents',
        'document_access_events' => 'Documents',
        // Tasks (rank 7)
        'tasks' => 'Tasks',
        'task_idempotency_keys' => 'Tasks',
        'task_participants' => 'Tasks',
        'task_comments' => 'Tasks',
        // WorkRecords (rank 8)
        'work_records' => 'WorkRecords',
        'work_record_idempotency_keys' => 'WorkRecords',
        // Cross-cutting infrastructure: this is the only table whose
        // migration may live under apps/api/Shared.
        'outbox_events' => 'Shared',
        // NOTE: `project_work_record_read_models` was an extra TABLE_OWNERS
        // key with no Schema::create migration. The architecture test now
        // asserts TABLE_OWNERS equals the set of migrated tables exactly;
        // virtual read models must not pollute TABLE_OWNERS. If a future
        // virtual resource requires inventory (e.g. an in-memory projection
        // surfaced by a module handler), register it in VIRTUAL_RESOURCES
        // rather than TABLE_OWNERS.
        'notifications' => 'Notifications',
        'notification_inbox' => 'Notifications',
        'notification_recipients' => 'Notifications',
        'notification_dead_letters' => 'Notifications',
        // Search (rank 11)
        'search_index_entries' => 'Search',
        'search_inbox' => 'Search',
        'search_checkpoints' => 'Search',
        // Reporting (rank 11)
        'report_definitions' => 'Reporting',
        'report_inbox' => 'Reporting',
        'report_read_models' => 'Reporting',
        'report_runs' => 'Reporting',
        'export_artifacts' => 'Reporting',
        'dashboard_definitions' => 'Reporting',
    ];

    /**
     * Existing infrastructure types that function as published Shared
     * surfaces until their Contracts migrations are separately authorized.
     *
     * @var list<string>
     */
    private const SHARED_INFRASTRUCTURE_IMPORT_ALLOWLIST = [
        'Shared\Infrastructure\Outbox\OutboxEventType',
        'Shared\Infrastructure\Streams\RedisStreamTransport',
    ];

    public function test_current_module_tree_obeys_the_repository_boundary_rules(): void
    {
        $this->assertSame([], $this->unapprovedViolationsIn(base_path()));
    }

    public function test_planned_modules_have_no_implementation_directory_yet(): void
    {
        $modulesPath = base_path('apps/api/Modules');
        $drifted = [];
        foreach (self::PLANNED_MODULES as $planned) {
            if (is_dir($modulesPath.DIRECTORY_SEPARATOR.$planned)) {
                $drifted[] = $planned;
            }
        }
        $this->assertSame(
            [],
            $drifted,
            'These modules are listed as planned for R2/R3 and must not yet have an apps/api/Modules directory: '
            .implode(', ', $drifted)
            .'. If you are implementing one, move it out of PLANNED_MODULES, add its rank, and register its tables in TABLE_OWNERS.'
        );
    }

    public function test_detects_a_cross_module_domain_import(): void
    {
        $root = $this->fixtureRoot();
        $this->writeFixture($root, 'Modules/WorkRecords/Features/Submit/Handler.php', <<<'PHP'
<?php
namespace Modules\WorkRecords\Features\Submit;
use Modules\Identity\Domain\User;
PHP);

        try {
            $violations = $this->violationsIn($root);

            $this->assertTrue(
                (bool) array_filter(
                    $violations,
                    static fn (string $violation): bool => str_contains(
                        $violation,
                        'WorkRecords may import Identity only through Contracts or Events.',
                    ),
                ),
                'Expected the WorkRecords->Identity surface violation in '.implode(' | ', $violations),
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_detects_cross_owner_join_and_foreign_key_in_a_module_migration(): void
    {
        $root = $this->fixtureRoot();
        $this->writeFixture($root, 'Modules/WorkRecords/Infrastructure/Persistence/Migrations/2026_create_work_records.php', <<<'PHP'
<?php
DB::statement('select * from work_records join users on users.id = work_records.user_id');
Schema::table('work_records', function ($table) {
    $table->foreignUuid('user_id')->constrained('users');
});
PHP);

        try {
            $violations = $this->violationsIn($root);

            $this->assertContains('WorkRecords SQL references Identity-owned table users.', $violations);
            $this->assertContains('WorkRecords migration references Identity-owned table users.', $violations);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_rejects_requests_as_a_business_module_or_identifier(): void
    {
        $root = $this->fixtureRoot();
        $this->writeFixture($root, 'Modules/Requests/Domain/RequestCreated.php', <<<'PHP'
<?php
namespace Modules\Requests\Domain;
final class RequestCreated {}
PHP);

        try {
            $violations = $this->violationsIn($root);

            $this->assertContains('Forbidden Requests business boundary: Modules/Requests.', $violations);
            $this->assertContains('Forbidden Request* business identifier: RequestCreated.', $violations);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_detects_a_business_controller_outside_its_module(): void
    {
        $root = $this->fixtureRoot();
        $this->writeFixture($root, 'app/Http/Controllers/Tasks/FakeTaskController.php', <<<'PHP'
<?php
namespace App\Http\Controllers\Tasks;
final class FakeTaskController {}
PHP);

        try {
            $this->assertContains(
                'Business controller must be module-owned: app/Http/Controllers/Tasks/FakeTaskController.php.',
                $this->violationsIn($root),
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_detects_business_table_access_below_app(): void
    {
        $root = $this->fixtureRoot();
        $this->writeFixture($root, 'app/Integrations/FakeTasksReader.php', <<<'PHP'
<?php
namespace App\Integrations;
use Illuminate\Support\Facades\DB;
final class FakeTasksReader
{
    public function read(): mixed
    {
        return DB::table('tasks')->first();
    }
}
PHP);

        try {
            $this->assertContains(
                'Business table access outside its owning module: app/Integrations/FakeTasksReader.php references tasks.',
                $this->violationsIn($root),
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_detects_business_table_access_from_a_module_http_controller(): void
    {
        $root = $this->fixtureRoot();
        $this->writeFixture($root, 'Modules/Tasks/Features/ListTasks/Http/FakeTaskController.php', <<<'PHP'
<?php
namespace Modules\Tasks\Features\ListTasks\Http;
use Illuminate\Support\Facades\DB;
final class FakeTaskController
{
    public function __invoke(): mixed
    {
        return DB::table('documents')->get();
    }
}
PHP);

        try {
            $this->assertContains(
                'Tasks HTTP controller must not access business table documents (owned by Documents): Modules/Tasks/Features/ListTasks/Http/FakeTaskController.php.',
                $this->violationsIn($root),
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_detects_outbox_ownership_in_a_module_http_controller(): void
    {
        $root = $this->fixtureRoot();
        $this->writeFixture($root, 'Modules/Tasks/Features/TransitionTask/Http/FakeTaskController.php', <<<'PHP'
<?php
namespace Modules\Tasks\Features\TransitionTask\Http;
use Shared\Contracts\TransactionalOutbox;
final class FakeTaskController
{
    public function __construct(private TransactionalOutbox $outbox) {}
}
PHP);

        try {
            $this->assertContains(
                'Tasks HTTP controller must not own transactions or Outbox: Modules/Tasks/Features/TransitionTask/Http/FakeTaskController.php.',
                $this->violationsIn($root),
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_rejects_a_business_controller_under_a_module_top_level_http_directory(): void
    {
        $root = $this->fixtureRoot();
        $this->writeFixture($root, 'Modules/Tasks/Http/FakeTasksController.php', <<<'PHP'
<?php
namespace Modules\Tasks\Http;
final class FakeTasksController {}
PHP);

        try {
            $this->assertContains(
                'Tasks HTTP boundary /Http/ may host only support APIs (ReportingApi/SearchApi); controllers must live under Features/<Feature>/Http (offender: Modules/Tasks/Http/FakeTasksController.php).',
                $this->violationsIn($root),
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_rejects_raw_outbox_access_outside_the_shared_adapter(): void
    {
        $root = $this->fixtureRoot();
        $this->writeFixture($root, 'Modules/WorkRecords/Features/Submit/Handler.php', <<<'PHP'
<?php
namespace Modules\WorkRecords\Features\Submit;
use Illuminate\Support\Facades\DB;
final class Handler
{
    public function handle(): void
    {
        DB::table('outbox_events')->insert(['event_id' => 'duplicate']);
    }
}
PHP);

        try {
            $this->assertContains(
                'WorkRecords must access Shared-owned outbox_events only through Shared\Contracts.',
                $this->violationsIn($root),
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_rejects_legacy_module_outbox_access_outside_the_shared_cutover_migration(): void
    {
        $root = $this->fixtureRoot();
        $this->writeFixture($root, 'Modules/PlatformSettings/Features/Settings/Handler.php', <<<'PHP'
<?php
namespace Modules\PlatformSettings\Features\Settings;
use Illuminate\Support\Facades\DB;
final class Handler
{
    public function handle(): void
    {
        DB::table('platform_settings_outbox')->insert(['id' => 'legacy']);
        DB::table('document_outbox_events')->insert(['id' => 'legacy']);
    }
}
PHP);

        try {
            $this->assertContains(
                'PlatformSettings must not access legacy module outbox table platform_settings_outbox; use Shared\\Contracts.',
                $this->violationsIn($root),
            );
            $this->assertContains(
                'PlatformSettings must not access legacy module outbox table document_outbox_events; use Shared\\Contracts.',
                $this->violationsIn($root),
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_rejects_cross_owner_infrastructure_import_from_a_producer(): void
    {
        $root = $this->fixtureRoot();
        $this->writeFixture($root, 'Modules/WorkRecords/Features/Submit/Handler.php', <<<'PHP'
<?php
namespace Modules\WorkRecords\Features\Submit;
use Modules\Identity\Infrastructure\Persistence\IdentityStore;
final class Handler {}
PHP);

        try {
            $this->assertContains(
                'WorkRecords must not import Identity Infrastructure.',
                $this->violationsIn($root),
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_rejects_shared_infrastructure_import_from_a_producer(): void
    {
        $root = $this->fixtureRoot();
        $this->writeFixture($root, 'Modules/WorkRecords/Features/Submit/Handler.php', <<<'PHP'
<?php
namespace Modules\WorkRecords\Features\Submit;
use Shared\Infrastructure\Outbox\DatabaseTransactionalOutbox;
final class Handler {}
PHP);

        try {
            $this->assertContains(
                'WorkRecords must not import Shared Infrastructure; depend on Shared\Contracts.',
                $this->violationsIn($root),
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_allows_raw_outbox_access_inside_the_shared_adapter(): void
    {
        $root = $this->fixtureRoot();
        $this->writeFixture($root, 'Shared/Infrastructure/Outbox/DatabaseTransactionalOutbox.php', <<<'PHP'
<?php
namespace Shared\Infrastructure\Outbox;
use Illuminate\Support\Facades\DB;
final class DatabaseTransactionalOutbox
{
    public function append(): void
    {
        DB::table('outbox_events')->insert(['event_id' => 'shared']);
    }
}
PHP);

        try {
            $this->assertSame([], $this->violationsIn($root));
        } finally {
            $this->removeDirectory($root);
        }
    }

    /**
     * The guard uses PHP's tokenizer to inspect executable imports and string literals,
     * then tokenizes SQL literals before interpreting table references. It deliberately
     * avoids scanning comments or applying a repository-wide text regex.
     *
     * @return list<string>
     */
    private function violationsIn(string $root): array
    {
        $modulesPath = $root.'/Modules';
        $violations = [];
        foreach (is_dir($modulesPath) ? (glob($modulesPath.'/*', GLOB_ONLYDIR) ?: []) : [] as $modulePath) {
            $module = basename($modulePath);
            if ($module === 'Requests') {
                $violations[] = 'Forbidden Requests business boundary: Modules/Requests.';
            }

            $isKnownModule = array_key_exists($module, self::MODULE_RANKS);
            if (! $isKnownModule && $module !== 'Requests') {
                $violations[] = "Unknown business module: {$module}.";

                continue;
            }

            foreach ($this->phpFilesIn($modulePath) as $path) {
                $source = file_get_contents($path);
                if ($source === false) {
                    $violations[] = "Unable to parse {$path}.";

                    continue;
                }
                $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));

                if ($isKnownModule) {
                    $allowedImports = $this->allowedImportsForCrossModuleException($module, $relativePath);
                    foreach ($this->importsFrom($source) as $import) {
                        $violations = [...$violations, ...$this->importViolations($module, $import, $relativePath, $allowedImports)];
                    }
                }

                if (! str_contains($relativePath, '/Tests/')) {
                    foreach ($this->allImportsFrom($source) as $import) {
                        $violations = [
                            ...$violations,
                            ...$this->infrastructureImportViolations($module, $import),
                        ];
                    }
                }
                if (
                    ! str_contains($relativePath, '/Tests/')
                    && in_array('outbox_events', $this->tablesInDatabaseCalls($source), true)
                ) {
                    $violations[] = "{$module} must access Shared-owned outbox_events only through Shared\Contracts.";
                }
                if (! str_contains($relativePath, '/Tests/')) {
                    foreach (array_intersect(self::LEGACY_MODULE_OUTBOX_TABLES, $this->tablesInDatabaseCalls($source)) as $legacyOutbox) {
                        $violations[] = "{$module} must not access legacy module outbox table {$legacyOutbox}; use Shared\\Contracts.";
                    }
                }

                foreach ($this->businessIdentifiersFrom($source) as $identifier) {
                    if (str_starts_with($identifier, 'Request')) {
                        $violations[] = "Forbidden Request* business identifier: {$identifier}.";
                    }
                }

                if ($isKnownModule) {
                    foreach ($this->sqlLiteralsFrom($source) as $sql) {
                        foreach ($this->tablesInSql($sql) as $table) {
                            $violations = [...$violations, ...$this->tableOwnershipViolations($module, $table, 'SQL')];
                        }
                    }

                    $containsHttpController = str_contains($path, DIRECTORY_SEPARATOR.'Http'.DIRECTORY_SEPARATOR)
                        && array_filter(
                            $this->businessIdentifiersFrom($source),
                            static fn (string $identifier): bool => str_ends_with($identifier, 'Controller'),
                        ) !== [];
                    if ($containsHttpController) {
                        $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
                        foreach ($this->tablesInDatabaseCalls($source) as $table) {
                            if (! array_key_exists($table, self::TABLE_OWNERS)) {
                                continue;
                            }
                            $owner = self::TABLE_OWNERS[$table];
                            if ($owner === $module) {
                                continue;
                            }
                            $violations[] = "{$module} HTTP controller must not access business table {$table} (owned by {$owner}): {$relativePath}.";
                        }
                        if (in_array('Shared\Contracts\TransactionalOutbox', $this->allImportsFrom($source), true)) {
                            $violations[] = "{$module} HTTP controller must not own transactions or Outbox: {$relativePath}.";
                        }
                    }

                    if (str_contains($path, DIRECTORY_SEPARATOR.'Migrations'.DIRECTORY_SEPARATOR)) {
                        foreach ($this->stringLiteralsFrom($source) as $literal) {
                            $violations = [...$violations, ...$this->tableOwnershipViolations($module, $literal, 'migration')];
                        }
                    }

                    // @phpstan-ignore-next-line if.alwaysTrue
                    if ($isKnownModule) {
                        $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
                        if (preg_match('#^Modules/'.preg_quote($module, '#').'/Http/.+Controller\.php$#', $relativePath) === 1) {
                            $violations[] = "{$module} HTTP boundary /Http/ may host only support APIs (ReportingApi/SearchApi); controllers must live under Features/<Feature>/Http (offender: {$relativePath}).";
                        }
                    }
                }
            }
        }

        $appPath = $root.'/app';
        if (is_dir($appPath)) {
            foreach ($this->phpFilesIn($appPath) as $path) {
                $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
                $source = file_get_contents($path);
                if ($source === false) {
                    $violations[] = "Unable to parse {$relativePath}.";

                    continue;
                }

                if (str_starts_with($relativePath, 'app/Http/Controllers/')
                    && basename($relativePath) !== 'Controller.php'
                    && str_ends_with($relativePath, 'Controller.php')) {
                    $violations[] = "Business controller must be module-owned: {$relativePath}.";
                }

                foreach ($this->tablesInDatabaseCalls($source) as $table) {
                    if (array_key_exists($table, self::TABLE_OWNERS)) {
                        $violations[] = "Business table access outside its owning module: {$relativePath} references {$table}.";
                    }
                }
            }
        }

        return array_values(array_unique($violations));
    }

    /** @return list<string> */
    private function unapprovedViolationsIn(string $root): array
    {
        return array_values(array_filter(
            $this->violationsIn($root),
            static function (string $violation): bool {
                foreach (ModulePlacementInventory::misplacedBusinessFiles() as $entry) {
                    if (str_contains($violation, $entry['path'])) {
                        return false;
                    }
                }

                // Suppress the rank-rule violation that is allowed by a
                // path-scoped CROSS_MODULE_IMPORT_EXCEPTIONS entry,
                // scoped to the exact (file, source, target) tuple. The
                // Contracts/Events surface violation is never suppressed
                // by this filter; only the rank dependency is.
                foreach (self::CROSS_MODULE_IMPORT_EXCEPTIONS as $entry) {
                    $source = $entry['source'];
                    $target = $entry['target'];
                    $rankMarker = "{$source} cannot depend on same-or-higher-rank module {$target}.";
                    $pathMarker = "[path:{$entry['path']}]";
                    if (str_contains($violation, $rankMarker) && str_contains($violation, $pathMarker)) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    /** @return list<string> */
    private function importsFrom(string $source): array
    {
        return array_values(array_filter(
            $this->allImportsFrom($source),
            static fn (string $import): bool => str_starts_with($import, 'Modules\\'),
        ));
    }

    /** @return list<string> */
    private function allImportsFrom(string $source): array
    {
        $imports = [];
        $tokens = token_get_all($source);
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            if (! is_array($tokens[$index]) || $tokens[$index][0] !== T_USE) {
                continue;
            }

            $name = '';
            for ($index++; $index < $count && $tokens[$index] !== ';'; $index++) {
                $name .= is_array($tokens[$index]) ? $tokens[$index][1] : $tokens[$index];
            }

            foreach (explode(',', $name) as $candidate) {
                $candidate = trim(explode(' as ', trim($candidate), 2)[0]);
                if ($candidate !== '') {
                    $imports[] = $candidate;
                }
            }
        }

        return $imports;
    }
    /**
     * Return the per-file allow-list of cross-module imports from
     * CROSS_MODULE_IMPORT_EXCEPTIONS. The match is exact on `(source,
     * relativePath)`. A non-empty list means the rank rule is suppressed
     * for the listed imports only; the imported target module MUST still
     * equal the exception entry's `target`, and the import MUST already be
     * a published Contracts/Events surface — this allow-list does not
     * permit `Infrastructure` or `Domain` imports.
     *
     * @return list<string>
     */
    private function allowedImportsForCrossModuleException(string $sourceModule, string $relativePath): array
    {
        foreach (self::CROSS_MODULE_IMPORT_EXCEPTIONS as $entry) {
            if ($entry['source'] === $sourceModule
                && $entry['path'] === $relativePath) {
                return $entry['imports'];
            }
        }

        return [];
    }

    /**
     * @param  list<string>  $allowedImports  effective allow-list for rank-rule
     *         exceptions (matches the exception entry's `imports` list when
     *         the file/path/source/target pair is in CROSS_MODULE_IMPORT_EXCEPTIONS,
     *         otherwise empty).
     */
    private function importViolations(string $sourceModule, string $import, string $relativePath = '', array $allowedImports = []): array
    {
        $parts = explode('\\', $import);
        $targetModule = $parts[1] ?? '';
        $publishedSurface = $parts[2] ?? '';

        if ($targetModule === $sourceModule) {
            return [];
        }

        if (! array_key_exists($targetModule, self::MODULE_RANKS)) {
            return ["{$sourceModule} imports unknown module {$targetModule}."];
        }

        $pathSuffix = $relativePath === '' ? '' : " [path:{$relativePath}]";
        $violations = [];
        if (! in_array($publishedSurface, ['Contracts', 'Events'], true)) {
            $violations[] = "{$sourceModule} may import {$targetModule} only through Contracts or Events.{$pathSuffix}";
        }

        if (self::MODULE_RANKS[$targetModule] >= self::MODULE_RANKS[$sourceModule]) {
            // The rank rule is suppressed only when the exact (file, source, target, import)
            // tuple is in CROSS_MODULE_IMPORT_EXCEPTIONS. Any other reverse edge fails closed.
            $rankExceptionMatch = $allowedImports !== []
                && in_array($import, $allowedImports, true);
            if (! $rankExceptionMatch) {
                $violations[] = "{$sourceModule} cannot depend on same-or-higher-rank module {$targetModule}.{$pathSuffix}";
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function infrastructureImportViolations(string $sourceModule, string $import): array
    {
        if (str_starts_with($import, 'Shared\Infrastructure\\')) {
            if (in_array($import, self::SHARED_INFRASTRUCTURE_IMPORT_ALLOWLIST, true)) {
                return [];
            }

            return ["{$sourceModule} must not import Shared Infrastructure; depend on Shared\Contracts."];
        }

        if (! str_starts_with($import, 'Modules\\')) {
            return [];
        }

        $parts = explode('\\', $import);
        $targetModule = $parts[1] ?? '';
        $surface = $parts[2] ?? '';
        if ($targetModule === $sourceModule || $surface !== 'Infrastructure') {
            return [];
        }

        return ["{$sourceModule} must not import {$targetModule} Infrastructure."];
    }

    /** @return list<string> */
    private function businessIdentifiersFrom(string $source): array
    {
        $identifiers = [];
        $tokens = token_get_all($source);
        $expectsName = false;

        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $expectsName = true;

                continue;
            }

            if ($expectsName && is_array($token) && $token[0] === T_STRING) {
                $identifiers[] = $token[1];
                $expectsName = false;
            }
        }

        return $identifiers;
    }

    /** @return list<string> */
    private function sqlLiteralsFrom(string $source): array
    {
        return array_values(array_filter(
            $this->stringLiteralsFrom($source),
            static fn (string $literal): bool => preg_match('/\b(?:select|insert|update|delete|join|references)\b/i', $literal) === 1,
        ));
    }

    /** @return list<string> */
    private function stringLiteralsFrom(string $source): array
    {
        $literals = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $literals[] = stripcslashes(substr($token[1], 1, -1));
            }
        }

        return $literals;
    }

    /** @return list<string> */
    private function tablesInSql(string $sql): array
    {
        preg_match_all('/`[^`]+`|[A-Za-z_][A-Za-z0-9_]*/', $sql, $matches);
        $tokens = array_map(static fn (string $token): string => trim($token, '`'), $matches[0]);
        $tables = [];

        foreach ($tokens as $index => $token) {
            if (in_array(strtolower($token), ['from', 'join', 'into', 'update', 'references'], true) && isset($tokens[$index + 1])) {
                $tables[] = strtolower($tokens[$index + 1]);
            }
        }

        return $tables;
    }

    /** @return list<string> */
    private function tablesInDatabaseCalls(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn (array|string $token): bool => ! is_array($token)
                || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $tables = [];

        for ($index = 0, $count = count($tokens); $index + 5 < $count; $index++) {
            if (! is_array($tokens[$index]) || $tokens[$index][0] !== T_STRING || $tokens[$index][1] !== 'DB') {
                continue;
            }
            if ((! is_array($tokens[$index + 1]) || $tokens[$index + 1][0] !== T_DOUBLE_COLON)
                || ! is_array($tokens[$index + 2])
                || $tokens[$index + 2][0] !== T_STRING
                || $tokens[$index + 2][1] !== 'table'
                || $tokens[$index + 3] !== '('
                || ! is_array($tokens[$index + 4])
                || $tokens[$index + 4][0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $tables[] = strtolower(stripcslashes(substr($tokens[$index + 4][1], 1, -1)));
        }

        return array_values(array_unique($tables));
    }

    /** @return list<string> */
    private function tableOwnershipViolations(string $module, string $table, string $surface): array
    {
        $normalized = strtolower($table);
        if ($normalized === 'requests') {
            return ["Forbidden Requests business table in {$module} {$surface}."];
        }

        $owner = self::TABLE_OWNERS[$normalized] ?? null;
        if ($owner === null || $owner === $module) {
            return [];
        }

        return ["{$module} {$surface} references {$owner}-owned table {$normalized}."];
    }

    /** @return list<string> */
    private function phpFilesIn(string $path): array
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        $files = [];

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function fixtureRoot(): string
    {
        $path = sys_get_temp_dir().'/cluster-module-guard-'.bin2hex(random_bytes(8));
        mkdir($path, 0700, true);

        return $path;
    }

    private function writeFixture(string $root, string $relativePath, string $source): void
    {
        $path = $root.'/'.$relativePath;
        if (! is_dir(dirname($path))) { mkdir(dirname($path), 0700, true); }
        file_put_contents($path, $source);
    }

    private function removeDirectory(string $path): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }

    public function test_every_misplaced_file_has_a_reason_a_non_past_expiry_and_an_existing_path(): void
    {
        $entries = ModulePlacementInventory::misplacedBusinessFiles();

        $shapeMessages = self::inventoryShapeMessages($entries);
        $pathMessages = $this->inventoryPathExistenceFailures($entries);

        $allMessages = [...$shapeMessages, ...$pathMessages];
        $this->assertSame(
            [],
            $allMessages,
            'Placement-inventory shape mismatch: '.implode(' | ', $allMessages),
        );
    }

    public function test_every_event_type_in_outbox_has_a_matching_json_schema(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $contractsDir = $repoRoot.'/docs/contracts/schemas';
        if (! is_dir($contractsDir)) {
            $this->markTestSkipped('docs/contracts/schemas is not present in this checkout.');
        }

        $eventTypes = [];
        // Match any literal that looks like a CloudEvents reverse-DNS event
        // type: `com.cluster.<module>.<name>.v<n>`. The two production shapes
        // are `'com.cluster.foo.bar.v1'` (return or argument value) and
        // `'event_type' => 'com.cluster.foo.bar.v1'` (DB insert map). The
        // regex below catches both. We then restrict to event types that
        // are registered in the OutboxEventType enum so the architecture
        // test enforces the contract without demanding per-event schemas
        // for every literal that happens to mention a CloudEvents type
        // (for example, fixture types in tests or invalid-event types
        // used as negative assertions).
        $allowed = array_map(
            static fn (\Shared\Infrastructure\Outbox\OutboxEventType $case): string => $case->value,
            \Shared\Infrastructure\Outbox\OutboxEventType::cases(),
        );
        $regex = '/[\'\"](com\.cluster\.[a-z][a-z0-9_-]*\.[a-z][a-z0-9_-]*\.v\d+)[\'\"]/';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $repoRoot.'/apps/api/Modules',
                FilesystemIterator::SKIP_DOTS,
            ),
        );
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if ($source === false) {
                continue;
            }
            if (preg_match_all($regex, $source, $matches) > 0) {
                foreach ($matches[1] as $eventType) {
                    if (! in_array($eventType, $allowed, true)) {
                        continue;
                    }
                    $eventTypes[$eventType] = $file->getPathname();
                }
            }
        }
        $this->assertNotEmpty(
            $eventTypes,
            'No event_type strings found under apps/api/Modules. Either no outbox events exist yet (skip the test) or the search regex is out of date.'
        );

        $missing = [];
        $placeholder = [];
        foreach ($eventTypes as $eventType => $sourceFile) {
            $slug = str_replace('.', '-', $eventType);
            $candidates = [
                $contractsDir.'/'.$slug.'.schema.json',
                $contractsDir.'/'.$eventType.'.schema.json',
            ];
            $found = null;
            foreach ($candidates as $candidate) {
                if (is_file($candidate)) {
                    $found = $candidate;
                    break;
                }
            }
            if ($found === null) {
                $missing[] = sprintf('%s (referenced in %s)', $eventType, $sourceFile);

                continue;
            }

            $raw = file_get_contents($found);
            if ($raw === false) {
                $placeholder[] = sprintf('%s (unable to read %s)', $eventType, $found);

                continue;
            }
            try {
                $schema = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $placeholder[] = sprintf('%s (%s is not valid JSON)', $eventType, $found);

                continue;
            }
            if (! is_array($schema) || ! isset($schema['properties']['data']['type']) || $schema['properties']['data']['type'] !== 'object') {
                $placeholder[] = sprintf('%s (%s has no top-level `data` object schema)', $eventType, $found);
            }
        }

        $this->assertSame(
            [],
            $missing,
            'Outbox event types are missing JSON schemas under docs/contracts/schemas. '
            .'Add a schema file for each missing type (filename: <type-with-dots-as-dashes>.schema.json) or drop the event_type from code.'
        );
        $this->assertSame(
            [],
            $placeholder,
            'Outbox event schemas are placeholders (no top-level `data` object). '
            .'Each schema must declare a Draft 2020-12 document with a top-level `data` object whose properties reflect the actual payload emitted by the producer.'
        );
    }

    public function test_module_migration_manifest_registers_every_live_migration_exactly_once(): void
    {
        $registered = array_values(config('module_migrations', []));
        foreach ($registered as $path) {
            $this->assertIsString($path);
            $this->assertFileExists($path, sprintf('Registered migration does not exist: %s', $path));
        }
        $counts = array_count_values($registered);
        $duplicates = array_keys(array_filter($counts, static fn (int $count): bool => $count !== 1));
        $this->assertSame([], $duplicates, 'Migration manifest paths must be registered exactly once.');

        $superseded = array_map(
            static fn (string $path): string => base_path($path),
            self::SUPERSEDED_MIGRATIONS,
        );
        foreach ($superseded as $path) {
            $this->assertFileExists($path, sprintf('Classified superseded migration does not exist: %s', $path));
        }
        $this->assertSame(
            [],
            array_values(array_intersect($registered, $superseded)),
            'Superseded migrations must not be registered alongside their replacements.',
        );

        $discovered = [];
        foreach ([
            base_path('Modules/*/Infrastructure/Persistence/Migrations/*.php'),
            base_path('Modules/*/Infrastructure/Outbox/Migrations/*.php'),
            base_path('Shared/Infrastructure/Outbox/Migrations/*.php'),
        ] as $pattern) {
            foreach (glob($pattern) ?: [] as $path) {
                $discovered[] = $path;
            }
        }
        $live = array_values(array_diff(array_unique($discovered), $superseded));
        sort($live);
        sort($registered);

        $this->assertSame(
            $live,
            $registered,
            'Every live migration must be registered once; every unregistered migration must be explicitly classified as superseded.',
        );
    }

    public function test_every_migrated_table_has_an_owner_and_owners_match_actual_module_layout(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $tables = [];
        $moduleMap = [];

        $migrationPaths = [
            $repoRoot.'/apps/api/Modules/*/Infrastructure/Persistence/Migrations/*.php',
            $repoRoot.'/apps/api/Modules/*/Infrastructure/Outbox/Migrations/*.php',
            $repoRoot.'/apps/api/Shared/Infrastructure/Outbox/Migrations/*.php',
        ];
        foreach ($migrationPaths as $pattern) {
            foreach (glob($pattern) as $file) {
                if (basename($file) === 'MigrateLegacyModuleOutboxes.php') {
                    // Its Schema::create calls are rollback-only restorations of retired
                    // module outboxes; up() creates no live table and drops both after copy.
                    continue;
                }
                $moduleName = basename(dirname(dirname(dirname(dirname($file)))));
                $source = file_get_contents($file);
                if ($source === false) {
                    continue;
                }
                if (preg_match_all("/Schema::create\\s*\\(\\s*'([a-z_]+)'/i", $source, $matches) > 0) {
                    foreach ($matches[1] as $table) {
                        $tables[$table] = ($tables[$table] ?? 0) + 1;
                        $moduleMap[$table] = ($moduleMap[$table] ?? $moduleName);
                    }
                }
            }
        }

        $this->assertNotEmpty(
            $tables,
            'No Schema::create calls found under apps/api/Modules. Either migrations live elsewhere (move them under apps/api/Modules/<Module>/Infrastructure/Persistence/Migrations/) or this scan needs updating.'
        );

        $missing = [];
        $mismatched = [];
        foreach ($tables as $table => $count) {
            if (! array_key_exists($table, self::TABLE_OWNERS)) {
                $missing[] = sprintf('%s (declared by %s)', $table, $moduleMap[$table]);

                continue;
            }
            if (self::TABLE_OWNERS[$table] !== $moduleMap[$table]) {
                $mismatched[] = sprintf(
                    '%s: TABLE_OWNERS says %s but actual module is %s',
                    $table,
                    self::TABLE_OWNERS[$table],
                    $moduleMap[$table],
                );
            }
        }

        $ownershipMessages = self::ownershipShapeMessages(
            $tables,
            $moduleMap,
            self::TABLE_OWNERS,
        );
        $this->assertSame(
            [],
            $ownershipMessages,
            'TABLE_OWNERS shape mismatch: '.implode(' | ', $ownershipMessages),
        );
    }

    /**
     * @param  array<string, int>  $tables  table => occurrences discovered by the migration scan
     * @param  array<string, string>  $moduleMap  table => module that owns the migration file
     * @param  array<string, string>  $owners  the candidate TABLE_OWNERS map (keys are table names)
     * @return list<string> distinct human-readable rejection messages, one per violation class
     */
    public static function ownershipShapeMessages(array $tables, array $moduleMap, array $owners): array
    {
        $messages = [];

        $extra = array_values(array_diff(array_keys($owners), array_keys($tables)));
        if ($extra !== []) {
            $messages[] = sprintf(
                'extra-owner: TABLE_OWNERS contains entries without a Schema::create migration: %s.',
                implode(', ', $extra),
            );
        }

        $missing = [];
        foreach ($tables as $table => $_count) {
            if (! array_key_exists($table, $owners)) {
                $missing[] = sprintf('%s (declared by %s)', $table, $moduleMap[$table] ?? 'unknown');
            }
        }
        if ($missing !== []) {
            $messages[] = sprintf(
                'missing-owner: migrations declare tables without an owner in TABLE_OWNERS: %s.',
                implode(', ', $missing),
            );
        }

        $mismatched = [];
        foreach ($tables as $table => $_count) {
            if (! array_key_exists($table, $owners)) {
                continue;
            }
            if ($owners[$table] !== ($moduleMap[$table] ?? null)) {
                $mismatched[] = sprintf(
                    '%s: TABLE_OWNERS says %s but actual module is %s',
                    $table,
                    $owners[$table],
                    $moduleMap[$table] ?? 'unknown',
                );
            }
        }
        if ($mismatched !== []) {
            $messages[] = sprintf(
                'owner-mismatch: TABLE_OWNERS disagrees with the actual module that owns each migration: %s.',
                implode(', ', $mismatched),
            );
        }

        return $messages;
    }

    /**
     * Validate a placement-inventory candidate shape. Each rejection class
     * (missing reason, empty reason, missing expires_on, invalid expires_on,
     * expired expires_on, missing path existence) returns a distinct message
     * prefixed by a class discriminator so the architecture test reports
     * exactly which rule failed.
     *
     * @param  list<array<string, mixed>>  $entries  inventory candidates
     * @return list<string> distinct rejection messages, one per violation class
     */
    public static function inventoryShapeMessages(array $entries): array
    {
        $messages = [];
        $today = date('Y-m-d');

        foreach ($entries as $entry) {
            $path = is_string($entry['path'] ?? null) ? $entry['path'] : '';

            $reason = is_string($entry['reason'] ?? null) ? trim($entry['reason']) : '';
            if (! array_key_exists('reason', $entry)) {
                $messages[] = sprintf('missing-reason: entry %s must declare a non-empty `reason`.', $path !== '' ? $path : '(no path)');
            } elseif ($reason === '') {
                $messages[] = sprintf('empty-reason: entry %s has an empty `reason`.', $path !== '' ? $path : '(no path)');
            }

            if (! array_key_exists('expires_on', $entry)) {
                $messages[] = sprintf('missing-expires-on: entry %s must declare an ISO-8601 `expires_on` (legacy `expiry` is rejected).', $path !== '' ? $path : '(no path)');

                continue;
            }
            $expiresOn = (string) $entry['expires_on'];
            if ($expiresOn === '') {
                $messages[] = sprintf('empty-expires-on: entry %s has an empty `expires_on`.', $path !== '' ? $path : '(no path)');

                continue;
            }
            if (date_create($expiresOn) === false) {
                $messages[] = sprintf('invalid-expires-on: entry %s has an invalid ISO-8601 `expires_on`: %s.', $path !== '' ? $path : '(no path)', $expiresOn);

                continue;
            }
            if ($expiresOn < $today) {
                $messages[] = sprintf('expired-exception: entry %s has an expired `expires_on` (%s < %s).', $path !== '' ? $path : '(no path)', $expiresOn, $today);
            }
        }

        return $messages;
    }

    /**
     * @return list<string>
     */
    private function inventoryPathExistenceFailures(array $entries): array
    {
        $messages = [];
        foreach ($entries as $entry) {
            $path = is_string($entry['path'] ?? null) ? $entry['path'] : '';
            if ($path === '' || ! is_file(base_path($path))) {
                $messages[] = sprintf('missing-path: inventory entry %s does not exist on disk.', $path !== '' ? $path : '(no path)');
            }
        }

        return $messages;
    }

    // ------------------------------------------------------------------
    // Negative fixtures: prove each rejection class fires a distinct,
    // discriminator-prefixed message. These tests call the same helper
    // methods used by the live guard, so adding new rejection classes
    // requires updating the helper AND these fixtures.
    // ------------------------------------------------------------------

    public function test_ownership_shape_rejects_extra_owner_with_distinct_message(): void
    {
        $tables = ['work_records' => 1];
        $moduleMap = ['work_records' => 'WorkRecords'];
        $owners = [
            'work_records' => 'WorkRecords',
            'project_work_record_read_models' => 'WorkRecords',
        ];

        $messages = self::ownershipShapeMessages($tables, $moduleMap, $owners);

        $this->assertCount(1, $messages, 'expected exactly one rejection class: '.implode(' | ', $messages));
        $this->assertStringContainsString(
            'extra-owner:',
            $messages[0],
            'extra-owner rejection must use the `extra-owner:` discriminator so it is distinguishable from missing-owner or owner-mismatch.',
        );
        $this->assertStringContainsString('project_work_record_read_models', $messages[0]);
    }

    public function test_ownership_shape_rejects_missing_owner_with_distinct_message(): void
    {
        $tables = ['work_records' => 1, 'unowned_projection' => 1];
        $moduleMap = ['work_records' => 'WorkRecords', 'unowned_projection' => 'WorkRecords'];
        $owners = ['work_records' => 'WorkRecords'];

        $messages = self::ownershipShapeMessages($tables, $moduleMap, $owners);

        $this->assertCount(1, $messages, 'expected exactly one rejection class: '.implode(' | ', $messages));
        $this->assertStringContainsString(
            'missing-owner:',
            $messages[0],
            'missing-owner rejection must use the `missing-owner:` discriminator.',
        );
        $this->assertStringContainsString('unowned_projection', $messages[0]);
    }

    public function test_ownership_shape_rejects_owner_mismatch_with_distinct_message(): void
    {
        $tables = ['work_records' => 1];
        $moduleMap = ['work_records' => 'WorkRecords'];
        $owners = ['work_records' => 'Tasks'];

        $messages = self::ownershipShapeMessages($tables, $moduleMap, $owners);

        $this->assertCount(1, $messages, 'expected exactly one rejection class: '.implode(' | ', $messages));
        $this->assertStringContainsString(
            'owner-mismatch:',
            $messages[0],
            'owner-mismatch rejection must use the `owner-mismatch:` discriminator.',
        );
    }

    public function test_inventory_shape_rejects_missing_reason_with_distinct_message(): void
    {
        $entries = [
            ['path' => 'app/Support/MissingReasonFixture.php', 'expires_on' => '2999-12-31'],
        ];

        $messages = self::inventoryShapeMessages($entries);

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('missing-reason:', $messages[0]);
        $this->assertStringContainsString('MissingReasonFixture.php', $messages[0]);
    }

    public function test_inventory_shape_rejects_empty_reason_with_distinct_message(): void
    {
        $entries = [
            ['path' => 'app/Support/EmptyReasonFixture.php', 'reason' => '   ', 'expires_on' => '2999-12-31'],
        ];

        $messages = self::inventoryShapeMessages($entries);

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('empty-reason:', $messages[0]);
    }

    public function test_inventory_shape_rejects_missing_expires_on_with_distinct_message(): void
    {
        $entries = [
            ['path' => 'app/Support/MissingExpiryFixture.php', 'reason' => 'fixture reason'],
        ];

        $messages = self::inventoryShapeMessages($entries);

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('missing-expires-on:', $messages[0]);
    }

    public function test_inventory_shape_rejects_invalid_expires_on_with_distinct_message(): void
    {
        $entries = [
            ['path' => 'app/Support/InvalidExpiryFixture.php', 'reason' => 'fixture reason', 'expires_on' => 'not-a-date'],
        ];

        $messages = self::inventoryShapeMessages($entries);

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('invalid-expires-on:', $messages[0]);
    }

    public function test_inventory_shape_rejects_expired_expiry_with_distinct_message(): void
    {
        $entries = [
            [
                'path' => 'app/Support/ExpiredExpiryFixture.php',
                'reason' => 'fixture reason',
                'expires_on' => '2020-01-01',
            ],
        ];

        $messages = self::inventoryShapeMessages($entries);

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('expired-exception:', $messages[0]);
    }

    public function test_inventory_path_existence_rejects_missing_path_with_distinct_message(): void
    {
        $entries = [
            [
                'path' => 'app/Support/ThisFileDoesNotExist.php',
                'reason' => 'fixture reason',
                'expires_on' => '2999-12-31',
            ],
        ];

        $messages = $this->inventoryPathExistenceFailures($entries);

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('missing-path:', $messages[0]);
    }

    public function test_cross_module_import_exception_admits_exactly_one_producer_packet_and_no_other_edge(): void
    {
        $this->assertCount(
            1,
            self::CROSS_MODULE_IMPORT_EXCEPTIONS,
            'The boundary exception must declare exactly one (path, source, target) tuple; '
            .'broader reverse-edge exceptions weaken the rank rule.',
        );
        $entry = self::CROSS_MODULE_IMPORT_EXCEPTIONS[0];
        $this->assertSame(
            'Modules/Authorization/Infrastructure/Persistence/AuthorizationBootstrapState.php',
            $entry['path'],
            'Exception path must be the exact producer file; no module-wide exception.',
        );
        $this->assertSame('Authorization', $entry['source']);
        $this->assertSame('Audit', $entry['target']);
        $this->assertSame('AUTHORIZATION-AUDIT-PRODUCER', $entry['token']);
        $this->assertNotSame('', $entry['reason']);
        foreach ($entry['imports'] as $import) {
            $this->assertMatchesRegularExpression(
                '/\\AModules\\\\Audit\\\\Contracts\\\\[A-Za-z0-9_]+\\z/',
                $import,
                'Exception only allows Modules\\Audit\\Contracts\\* imports; Infrastructure/Domain must fail closed.',
            );
        }
    }

    public function test_cross_module_import_exception_does_not_suppress_contracts_surface_violation(): void
    {
        $root = $this->fixtureRoot();
        try {
            $this->writeFixture(
                $root,
                'Modules/Authorization/Infrastructure/Persistence/AuthorizationBootstrapState.php',
                <<<'PHP'
<?php
namespace Modules\Authorization\Infrastructure\Persistence;
use Modules\Audit\Infrastructure\Persistence\AuditStore;
final class AuthorizationBootstrapState {}
PHP,
            );
            $this->writeFixture(
                $root,
                'Modules/Authorization/Infrastructure/Persistence/AuthorizationIsoEvent.php',
                <<<'PHP'
<?php
namespace Modules\Authorization\Infrastructure\Persistence;
use Modules\Audit\Infrastructure\Persistence\AuditStore;
final class AuthorizationIsoEvent {}
PHP,
            );
            $violations = $this->unapprovedViolationsIn($root);
            $this->assertNotEmpty(
                $violations,
                'The exception admits exactly the Audit\\Contracts edge; Infrastructure or '
                .'Domain imports, or any other Authorization file, must remain violations.',
            );
            $this->assertTrue(
                (bool) array_filter(
                    $violations,
                    static fn (string $violation): bool => str_contains(
                        $violation,
                        'Authorization may import Audit only through Contracts or Events.',
                    ),
                ),
                'Infrastructure import from the excepted file must still surface as a '
                .'Contracts/Events surface violation even though the rank rule is suppressed.',
            );
            $this->assertTrue(
                (bool) array_filter(
                    $violations,
                    static fn (string $violation): bool => str_contains(
                        $violation,
                        'AuthorizationIsoEvent.php',
                    ),
                ),
                'Other Authorization files importing Audit must still be rejected.',
            );
            $this->assertTrue(
                (bool) array_filter(
                    $violations,
                    static fn (string $violation): bool => str_contains(
                        $violation,
                        'cannot depend on same-or-higher-rank module Audit.',
                    ) && str_contains(
                        $violation,
                        '[path:Modules/Authorization/Infrastructure/Persistence/AuthorizationIsoEvent.php]',
                    ),
                ),
                'Second Authorization file (outside the exception path) must still violate the rank rule.',
            );
        } finally {
            $this->removeDirectory($root);
        }
    }
}
