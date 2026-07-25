<?php

namespace Tests\Architecture;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class ModuleBoundariesTest extends TestCase
{
    /** @var array<string, int> */
    private const MODULE_RANKS = [
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
        'Audit',
        'RecordsGovernance',
        'Collaboration',
        'Workspace',
        'Strategy',
        'PortfolioProjects',
        'Risk',
    ];

    /** @var array<string, string> */
    private const TABLE_OWNERS = [
        // PlatformSettings (rank 0)
        'platform_settings' => 'PlatformSettings',
        'platform_setting_versions' => 'PlatformSettings',
        'platform_settings_outbox' => 'PlatformSettings',
        'platform_alert_policies' => 'PlatformSettings',
        'business_calendars' => 'PlatformSettings',
        'business_calendar_weekdays' => 'PlatformSettings',
        'business_calendar_exceptions' => 'PlatformSettings',
        'platform_maintenance_windows' => 'PlatformSettings',
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
        'identities' => 'Identity',
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
        // Authorization owns access_decisions AND sensitive_access_events (cross-cutting audit-events table is planned, not migrated)
        'audit_events' => 'Audit',
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
        'document_outbox_events' => 'Documents',
        // Tasks (rank 7)
        'tasks' => 'Tasks',
        'task_idempotency_keys' => 'Tasks',
        'task_participants' => 'Tasks',
        'task_comments' => 'Tasks',
        // WorkRecords (rank 8)
        'work_records' => 'WorkRecords',
        'work_record_idempotency_keys' => 'WorkRecords',
        'outbox_events' => 'WorkRecords',
        // Notifications (rank 11)
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

            $this->assertContains('WorkRecords may import Identity only through Contracts or Events.', $violations);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_detects_cross_owner_join_and_foreign_key_in_a_module_migration(): void
    {
        $root = $this->fixtureRoot();
        $this->writeFixture($root, 'Modules/WorkRecords/Infrastructure/Persistence/Migrations/2026_create_work_records.php', <<<'PHP'
<?php
DB::statement('select * from work_records join identities on identities.id = work_records.identity_id');
Schema::table('work_records', function ($table) {
    $table->foreignUuid('identity_id')->constrained('identities');
});
PHP);

        try {
            $violations = $this->violationsIn($root);

            $this->assertContains('WorkRecords SQL references Identity-owned table identities.', $violations);
            $this->assertContains('WorkRecords migration references Identity-owned table identities.', $violations);
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

                if ($isKnownModule) {
                    foreach ($this->importsFrom($source) as $import) {
                        $violations = [...$violations, ...$this->importViolations($module, $import)];
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
                }

                if ($isKnownModule && str_contains($path, DIRECTORY_SEPARATOR.'Migrations'.DIRECTORY_SEPARATOR)) {
                    foreach ($this->stringLiteralsFrom($source) as $literal) {
                        $violations = [...$violations, ...$this->tableOwnershipViolations($module, $literal, 'migration')];
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

    /** @return list<string> */
    private function importViolations(string $sourceModule, string $import): array
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

        $violations = [];
        if (! in_array($publishedSurface, ['Contracts', 'Events'], true)) {
            $violations[] = "{$sourceModule} may import {$targetModule} only through Contracts or Events.";
        }

        if (self::MODULE_RANKS[$targetModule] >= self::MODULE_RANKS[$sourceModule]) {
            $violations[] = "{$sourceModule} cannot depend on same-or-higher-rank module {$targetModule}.";
        }

        return $violations;
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
        mkdir(dirname($path), 0700, true);
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

    public function test_every_misplaced_file_has_a_non_expired_expiry_date(): void
    {
        $today = date('Y-m-d');

        foreach (ModulePlacementInventory::misplacedBusinessFiles() as $entry) {
            $this->assertArrayHasKey('path', $entry, 'misplaced entry must have a path key.');
            $this->assertArrayHasKey('expiry', $entry, "misplaced entry {$entry['path']} must have an expiry key.");
            $this->assertNotEmpty($entry['expiry'], "misplaced entry {$entry['path']} must have a non-empty expiry.");
            $this->assertNotEmpty(
                date_create($entry['expiry']),
                "misplaced entry {$entry['path']} has an invalid expiry date: {$entry['expiry']}.",
            );
            $this->assertGreaterThanOrEqual(
                $today,
                $entry['expiry'],
                "misplaced entry {$entry['path']} has expired (expiry: {$entry['expiry']}); remove it from ModulePlacementInventory or migrate the file.",
            );
        }
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
        foreach ($eventTypes as $eventType => $sourceFile) {
            $slug = str_replace('.', '-', $eventType);
            $candidates = [
                $contractsDir.'/'.$slug.'.schema.json',
                $contractsDir.'/'.$eventType.'.schema.json',
            ];
            $found = false;
            foreach ($candidates as $candidate) {
                if (is_file($candidate)) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $missing[] = sprintf('%s (referenced in %s)', $eventType, $sourceFile);
            }
        }

        $this->assertSame(
            [],
            $missing,
            'Outbox event types are missing JSON schemas under docs/contracts/schemas. '
            .'Add a schema file for each missing type (filename: <type-with-dots-as-dashes>.schema.json) or drop the event_type from code.'
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
        ];
        foreach ($migrationPaths as $pattern) {
            foreach (glob($pattern) as $file) {
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

        $this->assertSame(
            [],
            $missing,
            'Migration tables without an owner in TABLE_OWNERS. Add an entry to apps/api/tests/Architecture/ModuleBoundariesTest.php::TABLE_OWNERS for each missing table, mapping it to the module that owns its migration file.'
        );
        $this->assertSame(
            [],
            $mismatched,
            'TABLE_OWNERS disagrees with the actual module that owns each migration. Update the owner column to match the directory under apps/api/Modules that contains the migration file.'
        );
    }
}
