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
        // The target must probe the validator and runtime dependencies, then
        // validate the lean documentation tree directly. The legacy
        // docs/catalog.yaml registry was removed with the old docs portal.
        $this->assertStringContainsString(
            'scripts/validate-docs.sh',
            $output,
            'docs-validate must wire to scripts/validate-docs.sh',
        );
        $this->assertStringNotContainsString(
            'docs/catalog.yaml',
            $output,
            'docs-validate must not require the removed legacy catalog',
        );
        $this->assertStringContainsString(
            'scripts/validate-docs.sh is missing',
            $output,
            'docs-validate must surface a missing validator as a labeled failure',
        );
        $this->assertStringContainsString(
            'PyYAML is missing',
            $output,
            'docs-validate must surface a missing parser dependency',
        );
    }

    public function test_task1_docs_validate_runs_the_architecture_closure_validator(): void
    {
        // Task 1 wires the architecture-closure validator into the docs
        // documentation gate. The register and validator must both exist on
        // disk, the Makefile must expose the docs-validate target, and the
        // validator entry point must be invoked from scripts/validate-docs.sh.
        $makefile = (string) file_get_contents($this->repoRoot.'/Makefile');
        $validator = (string) file_get_contents($this->repoRoot.'/scripts/validate-docs.sh');

        $this->assertStringContainsString(
            'docs-validate:',
            $makefile,
            'Makefile must expose a docs-validate target',
        );
        $this->assertStringContainsString(
            'scripts/validate-architecture-closure.py',
            $validator,
            'validate-docs.sh must invoke the architecture-closure validator',
        );
        $this->assertFileExists(
            $this->repoRoot.'/scripts/validate-architecture-closure.py',
            'closure validator script must exist',
        );
        $this->assertFileExists(
            $this->repoRoot.'/docs/architecture/architecture-closure-register.yaml',
            'closure register YAML must exist',
        );
    }

    public function test_s9_docs_validate_fast_target_is_strict_prereq_alias_for_docs_validate(): void
    {
        [$exitCode, $output] = $this->runMake('-n', 'docs-validate-fast');

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString(
            'scripts/validate-docs.sh',
            $output,
            'docs-validate-fast must run the same validator as docs-validate',
        );
        $this->assertStringNotContainsString(
            'docs/catalog.yaml',
            $output,
            'docs-validate-fast must not restore the removed legacy catalog prerequisite',
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
            "/^verify-boundaries:\n\\t(?!#)(.+)/m",
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

    public function test_task1_closure_validator_rejects_missing_required_f_and_c_findings(): void
    {
        $base = self::validClosurePayload();

        foreach (['F020', 'C128'] as $missingId) {
            $payload = $base;
            $payload['findings'] = array_values(array_filter(
                $payload['findings'],
                static fn (array $finding): bool => $finding['id'] !== $missingId,
            ));

            [$exit, , $stderr] = $this->validateClosurePayload($payload);
            $this->assertSame(1, $exit, "validator must reject deletion of {$missingId}: {$stderr}");
            $this->assertStringContainsString($missingId, $stderr);
            $this->assertStringContainsString('required', $stderr);
        }
    }

    public function test_task1_closure_validator_rejects_malformed_or_pre_129_cycle_ids(): void
    {
        foreach (['C001', 'C123', 'C0128'] as $invalidId) {
            $payload = self::validClosurePayload();
            $payload['findings'][] = self::finding($invalidId, 1);

            [$exit, , $stderr] = $this->validateClosurePayload($payload);
            $this->assertSame(1, $exit, "validator must reject {$invalidId}: {$stderr}");
            $this->assertStringContainsString($invalidId, $stderr);
            $this->assertStringContainsString('C129', $stderr);
        }
    }

    public function test_task1_closure_validator_rejects_invalid_terminal_evidence_items(): void
    {
        $invalidEvidenceSets = [
            ['plain string'],
            [[]],
            [['kind' => 'baseline', 'value' => 'irrelevant']],
            [['kind' => 'source']],
            [['kind' => 'command', 'value' => '   ']],
        ];

        foreach ($invalidEvidenceSets as $evidence) {
            $payload = self::validClosurePayload();
            $payload['findings'][0]['status'] = 'closed';
            $payload['findings'][0]['evidence'] = $evidence;

            [$exit, , $stderr] = $this->validateClosurePayload($payload);
            $this->assertSame(1, $exit, 'validator must reject malformed terminal evidence: '.$stderr);
            $this->assertStringContainsString('F020', $stderr);
            $this->assertStringContainsString('evidence', $stderr);
        }
    }

    public function test_task1_closure_validator_requires_non_empty_c129_evidence_and_accepts_valid_extra(): void
    {
        foreach ([[['kind' => 'source']], [['kind' => 'command', 'value' => '']]] as $evidence) {
            $payload = self::validClosurePayload();
            $payload['findings'][] = self::finding('C129', 1, $evidence);

            [$exit, , $stderr] = $this->validateClosurePayload($payload);
            $this->assertSame(1, $exit, 'validator must reject empty C129 evidence value: '.$stderr);
            $this->assertStringContainsString('C129', $stderr);
            $this->assertStringContainsString('non-empty', $stderr);
        }

        $payload = self::validClosurePayload();
        $payload['findings'][] = self::finding('C129', 14);
        [$exit, , $stderr] = $this->validateClosurePayload($payload);
        $this->assertSame(0, $exit, 'validator must permit a valid C129+ extra: '.$stderr);
    }

    public function test_task1_closure_validator_rejects_terminal_sourced_false_entry(): void
    {
        $payload = self::validClosurePayload();
        $payload['findings'][0]['sourced'] = false;
        $payload['findings'][0]['status'] = 'closed';
        $payload['findings'][0]['claim'] = 'UNSOURCED — synthetic fixture.';

        [$exit, , $stderr] = $this->validateClosurePayload($payload);
        $this->assertSame(1, $exit, 'validator must reject terminal sourced:false entry: '.$stderr);
        $this->assertStringContainsString('F020', $stderr);
        $this->assertStringContainsString('sourced: false entries cannot be terminal', $stderr);
    }

    public function test_task1_closure_validator_rejects_owner_mapping_type_and_range_violations(): void
    {
        $fixtures = [
            ['index' => 0, 'owner' => 7, 'message' => 'owner_task must equal 6'],
            ['index' => 19, 'owner' => 12, 'message' => 'owner_task must equal 2'],
            ['index' => 20, 'owner' => 0, 'message' => 'owner_task must be an integer from 1 to 14'],
            ['index' => 21, 'owner' => 15, 'message' => 'owner_task must be an integer from 1 to 14'],
            ['index' => 22, 'owner' => '5', 'message' => 'owner_task must be an integer from 1 to 14'],
        ];

        foreach ($fixtures as $fixture) {
            $payload = self::validClosurePayload();
            $payload['findings'][$fixture['index']]['owner_task'] = $fixture['owner'];
            $findingId = $payload['findings'][$fixture['index']]['id'];

            [$exit, , $stderr] = $this->validateClosurePayload($payload);
            $this->assertSame(1, $exit, "validator must reject owner for {$findingId}: {$stderr}");
            $this->assertStringContainsString($findingId, $stderr);
            $this->assertStringContainsString($fixture['message'], $stderr);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function validClosurePayload(): array
    {
        $owners = [
            'F020' => 6, 'F023' => 8, 'F030' => 7, 'F033' => 8, 'F035' => 8,
            'F044' => 12, 'F046' => 12, 'F059' => 8, 'F067' => 8, 'F072' => 9,
            'F076' => 8, 'F078' => 4, 'F087' => 10, 'F089' => 10,
            'F112' => 10, 'F113' => 10, 'F115' => 10, 'F116' => 10,
            'F117' => 11, 'C124' => 2, 'C125' => 3, 'C126' => 4,
            'C127' => 5, 'C128' => 13,
        ];

        return [
            'version' => 3,
            'scope' => self::scopeBlock(),
            'findings' => array_map(
                static fn (string $id, int $owner): array => self::finding($id, $owner),
                array_keys($owners),
                array_values($owners),
            ),
        ];
    }

    /**
     * @param  array<int,mixed>  $evidence
     * @return array<string,mixed>
     */
    private static function finding(string $id, int $owner, array $evidence = [['kind' => 'source', 'value' => 'plan#1']]): array
    {
        return [
            'id' => $id,
            'domain' => 'contracts',
            'priority' => 'P2',
            'sourced' => true,
            'status' => 'open',
            'claim' => 'Synthetic behavioral fixture.',
            'exit_criteria' => 'Pass the focused validator fixture.',
            'owner_task' => $owner,
            'evidence' => $evidence,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function scopeBlock(): array
    {
        return [
            'decision' => 'user-approved scope amendment',
            'decision_date' => '2026-07-26',
            'historical_findings_tracked' => 19,
            'historical_findings_unrecoverable' => 104,
            'approved_historical_ids' => [
                'F020', 'F023', 'F030', 'F033', 'F035', 'F044', 'F046', 'F059',
                'F067', 'F072', 'F076', 'F078', 'F087', 'F089', 'F112', 'F113',
                'F115', 'F116', 'F117',
            ],
            'closure_wording' => 'Closure tracks the 19 documentable historical IDs and the C124-C128 cycle findings.',
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private static function buildSyntheticYaml(array $payload): string
    {
        // Lightweight YAML emitter for synthetic test payloads — the
        // `yaml` extension is not always installed, so we build the
        // scalar/sequence payload by hand. Inputs are well-known.
        $out = '';
        $scope = $payload['scope'] ?? [];
        $out .= 'scope:'."\n";
        foreach ($scope as $key => $value) {
            if (is_array($value)) {
                $out .= '  '.$key.':'."\n";
                foreach ($value as $item) {
                    $out .= '    - '.$item."\n";
                }
            } elseif (is_bool($value)) {
                $out .= '  '.$key.': '.($value ? 'true' : 'false')."\n";
            } elseif (is_int($value)) {
                $out .= '  '.$key.': '.$value."\n";
            } else {
                $escaped = str_replace("'", "''", (string) $value);
                $out .= '  '.$key.": '".$escaped."'"."\n";
            }
        }
        $out .= 'findings:'."\n";
        foreach ($payload['findings'] as $finding) {
            $out .= '  - id: '.$finding['id']."\n";
            $out .= '    domain: '.$finding['domain']."\n";
            $out .= '    priority: '.$finding['priority']."\n";
            $out .= '    sourced: '.($finding['sourced'] ? 'true' : 'false')."\n";
            $out .= '    status: '.$finding['status']."\n";
            $claim = str_replace("'", "''", $finding['claim']);
            $out .= "    claim: '".$claim."'"."\n";
            $exit = str_replace("'", "''", $finding['exit_criteria']);
            $out .= "    exit_criteria: '".$exit."'"."\n";
            $owner = $finding['owner_task'];
            $out .= '    owner_task: '.(is_int($owner) ? (string) $owner : "'".str_replace("'", "''", (string) $owner)."'")."\n";
            if (empty($finding['evidence'])) {
                $out .= '    evidence: []'."\n";
            } else {
                $out .= '    evidence:'."\n";
                foreach ($finding['evidence'] as $item) {
                    if (! is_array($item)) {
                        $out .= "      - '".str_replace("'", "''", (string) $item)."'\n";

                        continue;
                    }
                    if ($item === []) {
                        $out .= '      - {}'."\n";

                        continue;
                    }
                    $out .= '      - kind: '.$item['kind']."\n";
                    if (array_key_exists('value', $item)) {
                        $val = str_replace("'", "''", (string) $item['value']);
                        $out .= "        value: '".$val."'"."\n";
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{0:int,1:string,2:string}
     */
    private function validateClosurePayload(array $payload): array
    {
        $temp = tempnam(sys_get_temp_dir(), 'closure_register_');
        $this->assertNotFalse($temp);

        try {
            file_put_contents($temp, 'version: 3'."\n".self::buildSyntheticYaml($payload));

            return $this->runClosureValidator(
                PythonBinary::resolve(),
                $this->repoRoot.'/scripts/validate-architecture-closure.py',
                $temp,
            );
        } finally {
            @unlink($temp);
        }
    }

    /**
     * @return array{0:int,1:string,2:string}
     */
    private function runClosureValidator(string $python, string $validator, string $register): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open([$python, $validator, $register], $descriptors, $pipes, $this->repoRoot);
        if (! is_resource($process)) {
            $this->fail('Unable to start validator: '.$python.' '.escapeshellarg($validator).' '.escapeshellarg($register));
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [$exit, $stdout, $stderr];
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
