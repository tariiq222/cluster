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

    /** @var array<string, string> */
    private const TABLE_OWNERS = [
        'platform_settings' => 'PlatformSettings',
        'organizations' => 'Organization',
        'clusters' => 'Organization',
        'facility_types' => 'Organization',
        'facilities' => 'Organization',
        'organization_idempotency_keys' => 'Organization',
        'identities' => 'Identity',
        'authorizations' => 'Authorization',
        'audit_events' => 'Audit',
        'workflow_instances' => 'Workflow',
        'records_governance' => 'RecordsGovernance',
        'work_definitions' => 'WorkDefinitions',
        'documents' => 'Documents',
        'collaboration' => 'Collaboration',
        'tasks' => 'Tasks',
        'work_records' => 'WorkRecords',
        'strategy' => 'Strategy',
        'portfolio_projects' => 'PortfolioProjects',
        'risks' => 'Risk',
        'notifications' => 'Notifications',
        'search_index' => 'Search',
        'reporting_read_models' => 'Reporting',
        'workspace_items' => 'Workspace',
    ];

    public function test_current_module_tree_obeys_the_repository_boundary_rules(): void
    {
        $this->assertSame([], $this->violationsIn(base_path()));
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
        if (! is_dir($modulesPath)) {
            return [];
        }

        $violations = [];
        foreach (glob($modulesPath.'/*', GLOB_ONLYDIR) ?: [] as $modulePath) {
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
                }

                if ($isKnownModule && str_contains($path, DIRECTORY_SEPARATOR.'Migrations'.DIRECTORY_SEPARATOR)) {
                    foreach ($this->stringLiteralsFrom($source) as $literal) {
                        $violations = [...$violations, ...$this->tableOwnershipViolations($module, $literal, 'migration')];
                    }
                }
            }
        }

        return array_values(array_unique($violations));
    }

    /** @return list<string> */
    private function importsFrom(string $source): array
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
                if (str_starts_with($candidate, 'Modules\\')) {
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
}
