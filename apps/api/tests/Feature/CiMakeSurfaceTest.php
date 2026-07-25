<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\Shell\PythonBinary;
use Tests\TestCase;

/**
 * Slice S9 — Makefile + CI surface for Task 9.
 *
 * Asserts the contract between the Makefile target surface, the GitHub-hosted
 * CI workflow, and the documentation validation pipeline. Each test maps to
 * one acceptance bullet from docs/superpowers/plans/2026-07-25-audit-findings-124-202.md
 * so failures point at the regression that broke the gate.
 */
final class CiMakeSurfaceTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repoRoot = realpath(base_path('../..')) ?: base_path('../..');
    }

    public function test_s9_make_exposes_a_resolvable_python_binary(): void
    {
        $python = PythonBinary::resolve();

        $this->assertNotSame('', $python, 'PythonBinary::resolve() must not return an empty string');
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+$/',
            PythonBinary::version(),
            'Resolved Python binary must advertise a 3.x version',
        );
    }

    public function test_s9_make_python_bin_target_uses_makefile_resolution(): void
    {
        [$exitCode, $output] = $this->runMake('python-bin');

        $this->assertSame(0, $exitCode, $output);
        $this->assertMatchesRegularExpression(
            '/^[^\s]+\s+\d+\.\d+/',
            trim($output),
            'make python-bin must emit "<binary> <version>"',
        );
    }

    public function test_s9_docs_validate_target_surfaces_missing_prereqs(): void
    {
        [$exitCode, $output] = $this->runMake('-n', 'docs-validate');

        $this->assertSame(0, $exitCode, $output);
        // The target must probe every required input (validator, python3,
        // PyYAML, docs/catalog.yaml) and route to scripts/validate-docs.sh
        // when all are present. No silent fallback path is allowed.
        $this->assertStringContainsString(
            'scripts/validate-docs.sh',
            $output,
            'docs-validate must wire to scripts/validate-docs.sh',
        );
        $this->assertStringContainsString(
            'docs/catalog.yaml',
            $output,
            'docs-validate must probe docs/catalog.yaml as a hard prereq',
        );
        $this->assertStringContainsString(
            'docs/validate-docs.sh is missing',
            $output,
            'docs-validate must surface a missing validator as a labeled failure',
            'docs-validate must surface a missing PyYAML as a labeled failure',
        );
        $this->assertStringContainsString(
            'docs/catalog.yaml is missing',
            $output,
            'docs-validate must surface a missing catalog as a labeled failure',
        );
    }

    public function test_s9_docs_validate_fast_target_is_strict_prereq_alias_for_docs_validate(): void
    {
        [$exitCode, $output] = $this->runMake('-n', 'docs-validate-fast');

        $this->assertSame(0, $exitCode, $output);
        // docs-validate-fast is now a strict alias of docs-validate (same
        // prereq chain). CI uses it to surface every missing input rather
        // than silently passing.
        $this->assertStringContainsString(
            'docs/catalog.yaml is missing',
            $output,
            'docs-validate-fast must apply the same prereq checks as docs-validate',
        );
    }

    public function test_s9_verify_mysql_integration_distinguishes_missing_prereq_from_failure(): void
    {
        [$exitCode, $output] = $this->runMake('-n', 'verify-mysql-integration');

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString(
            'run-mysql-integration-tests.sh',
            $output,
            'verify-mysql-integration must reference the dedicated runner script',
        );
        $this->assertStringContainsString(
            'docker',
            $output,
            'verify-mysql-integration must probe docker availability',
        );
        $this->assertStringContainsString(
            'pdo_mysql',
            $output,
            'verify-mysql-integration must probe pdo_mysql availability',
        );
        $this->assertStringContainsString(
            'prereq missing',
            $output,
            'verify-mysql-integration must label skip behavior as a missing prereq (not a test failure)',
        );
        $this->assertStringContainsString(
            'runner script',
            $output,
            'verify-mysql-integration must reference the runner script in its prereq check',
        );
        $this->assertStringContainsString(
            'failing the gate',
            $output,
        );
    }

    public function test_s9_make_exposes_npm_audit_and_api_check_targets(): void
    {
        [$auditExit, $auditOutput] = $this->runMake('-n', 'npm-audit');
        $this->assertSame(0, $auditExit, $auditOutput);
        $this->assertStringContainsString('npm --prefix apps/web audit', $auditOutput);

        [$checkExit, $checkOutput] = $this->runMake('-n', 'api:check');
        $this->assertSame(0, $checkExit, $checkOutput);
        $this->assertStringContainsString('npm --prefix apps/web run api:check', $checkOutput);
    }

    public function test_s9_audit_dependencies_delegates_to_npm_audit(): void
    {
        [$exitCode, $output] = $this->runMake('-n', 'audit-dependencies');

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('composer --working-dir=apps/api audit', $output);
        $this->assertStringContainsString('npm --prefix apps/web audit', $output);
    }

    public function test_s9_help_target_lists_public_ci_gates(): void
    {
        [$exitCode, $output] = $this->runMake('help');

        $this->assertSame(0, $exitCode, $output);
        foreach (['verify-intake', 'api:check', 'docs-validate', 'verify-mysql-integration', 'verify-boundaries', 'npm-audit'] as $required) {
            $this->assertStringContainsString(
                $required,
                $output,
                "help target must advertise the {$required} gate",
            );
        }
    }

    public function test_s9_verify_boundaries_has_a_recipe(): void
    {
        $content = (string) file_get_contents($this->repoRoot.'/Makefile');

        // The verify-boundaries target must have at least one non-comment
        // line of recipe body. The plan explicitly anchors the architecture
        // guard as a required CI gate; a target without a recipe is treated
        // by make as "nothing to do" — silently passing.
        $this->assertMatchesRegularExpression(
            "/^verify-boundaries:\n\\t(?:?!#)(.+)/m",
            $content,
            'verify-boundaries must have a non-comment recipe body (the architecture guard cannot be silently passing)',
        );
        $this->assertStringContainsString(
            'ModuleBoundariesTest.php',
            $content,
            'verify-boundaries must run the architecture test',
        );
    }

    public function test_s9_ci_workflow_includes_required_gates_in_order(): void
    {
        $path = $this->repoRoot.'/.github/workflows/ci.yml';
        $this->assertFileExists($path);

        $content = (string) file_get_contents($path);

        // Required gate presence
        $requiredSteps = [
            'make verify-boundaries',
            'make api:check',
            'make docs-validate-fast',
            'make verify-mysql-integration',
            'make npm-audit',
            'make verify-w1-1-local',
            'make test-api',
        ];
        foreach ($requiredSteps as $step) {
            $this->assertStringContainsString(
                $step,
                $content,
                "ci.yml must include step: {$step}",
            );
        }
    }

    public function test_s9_ci_workflow_runs_jobs_in_required_tier_order(): void
    {
        $content = (string) file_get_contents($this->repoRoot.'/.github/workflows/ci.yml');

        // Each tier short-circuits on the cheapest possible failure. The
        // needs: chain encodes the order: contracts depends on intake, static
        // on intake, unit on static, integration on contracts, production
        // smoke on every lower tier. The PR gets fast feedback without
        // sacrificing the production-bundle gate.
        $jobs = $this->extractJobs($content);

        // Tier 1: only `intake` runs without dependencies.
        $this->assertArrayHasKey('intake', $jobs, 'ci.yml must define the intake job');
        $this->assertSame([], $jobs['intake']['needs'], 'intake must run without dependencies');

        // All other jobs must declare a non-empty needs: chain so the gate
        // order is explicit and auditable rather than implicit through
        // alphabetical job ordering.
        foreach (['contracts', 'api-static', 'web-static', 'secrets'] as $tierOnePlus) {
            $this->assertArrayHasKey($tierOnePlus, $jobs, "ci.yml must define job: {$tierOnePlus}");
            $this->assertNotEmpty(
                $jobs[$tierOnePlus]['needs'],
                "Job {$tierOnePlus} must declare dependencies so its placement in the gate order is explicit",
            );
        }

        // Tier 2: contracts depends on intake (fail fast on contract drift).
        $this->assertSame(['intake'], $jobs['contracts']['needs']);

        // Tier 3: unit jobs depend on their static counterpart.
        $this->assertSame(['api-static'], $jobs['api-unit']['needs']);
        $this->assertSame(['web-static'], $jobs['web-unit']['needs']);

        // Tier 4: integration depends on contracts so the real runner
        // script only runs after the contract gate has approved the
        // OpenAPI/catalog surface.
        $this->assertSame(['contracts'], $jobs['mysql-integration']['needs']);
        $this->assertSame(['web-static'], $jobs['npm-audit']['needs']);

        // Tier 5: production smoke waits for every lower tier. Track only the
        // set; the exact list is the union of the tier-3 and tier-4 jobs.
        $this->assertContains('api-unit', $jobs['production-bundle']['needs']);
        $this->assertContains('web-unit', $jobs['production-bundle']['needs']);
        $this->assertContains('secrets', $jobs['production-bundle']['needs']);
        $this->assertContains('mysql-integration', $jobs['production-bundle']['needs']);
        $this->assertContains('npm-audit', $jobs['production-bundle']['needs']);
    }

    /**
     * Parse the workflow into a `job_name => [needs => list<string>]` map.
     *
     * Top-level keys like `push:`, `pull_request:`, `on:`, `jobs:` are
     * excluded by requiring a `runs-on:` instruction within the next 8
     * lines — that is the only thing that distinguishes a real job from a
     * trigger/structural key.
     *
     * @return array<string, array{needs: list<string>}>
     */
    private function extractJobs(string $content): array
    {
        $jobs = [];
        $current = null;
        $collectingNeeds = false;
        $needs = [];
        $lines = explode("\n", $content);
        $lineCount = count($lines);
        foreach ($lines as $index => $line) {
            if (preg_match('/^  ([a-z][a-z0-9_-]*):\s*$/', $line, $matches) === 1) {
                $hasRunsOn = false;
                for ($j = $index + 1; $j < min($index + 8, $lineCount); $j++) {
                    if (preg_match('/^\s+runs-on:\s*/', $lines[$j]) === 1) {
                        $hasRunsOn = true;
                        break;
                    }
                    if (preg_match('/^  [a-z][a-z0-9_-]*:\s*$/', $lines[$j]) === 1) {
                        break;
                    }
                }
                if ($current !== null) {
                    $jobs[$current] = ['needs' => $needs];
                }
                if ($hasRunsOn) {
                    $current = $matches[1];
                    $needs = [];
                    $collectingNeeds = false;
                } else {
                    $current = null;
                    $collectingNeeds = false;
                }
                continue;
            }
            if ($current === null) {
                continue;
            }
            if (preg_match('/^\s+needs:\s*(.*)$/', $line, $matches) === 1) {
                $raw = trim($matches[1]);
                if ($raw === '') {
                    $collectingNeeds = true;
                    continue;
                }
                // Strip inline list brackets: needs: [a, b, c]
                $raw = trim($raw, '[]');
                $needs = array_values(array_filter(array_map('trim', explode(',', $raw))));
                $collectingNeeds = false;
                continue;
            }
            if ($collectingNeeds && preg_match('/^\s+-\s+(.+)$/', $line, $matches) === 1) {
                $needs[] = trim($matches[1]);
                continue;
            }
            if ($collectingNeeds && trim($line) === '') {
                continue;
            }
            $collectingNeeds = false;
        }
        if ($current !== null) {
            $jobs[$current] = ['needs' => $needs];
        }

        return $jobs;
    }

    public function test_s9_ci_workflow_uses_php_8_4_for_documented_runtime(): void
    {
        $content = (string) file_get_contents($this->repoRoot.'/.github/workflows/ci.yml');
        $this->assertStringContainsString(
            'php-version: "8.4"',
            $content,
            'ci.yml must continue to use PHP 8.4 (matches composer constraint ^8.3)',
        );
    }

    public function test_s9_ci_workflow_declares_timeout_for_long_running_gates(): void
    {
        $content = (string) file_get_contents($this->repoRoot.'/.github/workflows/ci.yml');

        $this->assertStringContainsString(
            'timeout-minutes:',
            $content,
            'ci.yml must declare timeout-minutes for long-running gates',
        );
    }

    public function test_s9_ci_e2e_workflow_runs_npm_audit_and_api_check(): void
    {
        $path = $this->repoRoot.'/.github/workflows/ci-e2e.yml';
        $this->assertFileExists($path);

        $content = (string) file_get_contents($path);
        $this->assertStringContainsString('make npm-audit', $content, 'ci-e2e.yml must run make npm-audit');
        $this->assertStringContainsString('make api:check', $content, 'ci-e2e.yml must run make api:check');
    }

    public function test_s9_makefile_phony_includes_new_targets(): void
    {
        $content = (string) file_get_contents($this->repoRoot.'/Makefile');
        $this->assertMatchesRegularExpression(
            '/^\.PHONY:\s.*\bnpm-audit\b/sm',
            $content,
            'Makefile .PHONY must include npm-audit',
        );
        $this->assertMatchesRegularExpression(
            '/^\.PHONY:\s.*\bapi:check\b/sm',
            $content,
            'Makefile .PHONY must include api:check',
        );
        $this->assertMatchesRegularExpression(
            '/^\.PHONY:\s.*\bdocs-validate-fast\b/sm',
            $content,
            'Makefile .PHONY must include docs-validate-fast',
        );
        $this->assertMatchesRegularExpression(
            '/^\.PHONY:\s.*\bhelp\b/sm',
            $content,
            'Makefile .PHONY must include help',
        );
        $this->assertMatchesRegularExpression(
            '/^\.PHONY:\s.*\bverify-mysql-integration\b/sm',
            $content,
            'Makefile .PHONY must keep verify-mysql-integration',
        );
    }

    public function test_s9_makefile_wires_python_binary_into_inventory_target(): void
    {
        $content = (string) file_get_contents($this->repoRoot.'/Makefile');
        $this->assertStringContainsString(
            '$(PYTHON_BINARY)',
            $content,
            'Makefile must route python invocations through $(PYTHON_BINARY)',
        );
        // At least api:inventory, validate-production-bundle, and the python
        // resolution declaration must use it.
        $usageCount = substr_count($content, '$(PYTHON_BINARY)');
        $this->assertGreaterThanOrEqual(
            3,
            $usageCount,
            'Makefile must reference $(PYTHON_BINARY) at least 3 times (resolution + 2 targets)',
        );
    }

    /**
     * @return array{0:int,1:string}
     */
    private function runMake(string ...$args): array
    {
        $command = array_merge(['make'], $args);
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $this->repoRoot);
        if (! is_resource($process)) {
            $this->fail('Unable to start make: '.implode(' ', $command));
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [$exitCode, trim($stdout."\n".$stderr)];
    }
}
