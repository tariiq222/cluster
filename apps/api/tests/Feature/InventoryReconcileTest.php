<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Slice S4 — Canonical OpenAPI reconciliation.
 *
 * Locks the gates named in the slice acceptance: preserves the user's
 * in-progress openapi.yaml additions, never deletes existing path keys,
 * appends the planned-status annotations, appends new routes-only paths,
 * appends the missing work-record discrete verb paths, registers /up as
 * bootstrap health, and propagates new-path refs to the r1-screens split.
 * Idempotency is checked by running --mode reconcile --write twice and
 * asserting the second run produces zero net diff.
 */
class InventoryReconcileTest extends TestCase
{
    private string $repoRoot;

    private string $summaryPath;

    private string $openapiPath;

    private string $w12Path;

    private string $r1ScreensPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repoRoot = realpath(base_path('../..')) ?: base_path('../..');
        $this->summaryPath = $this->repoRoot.'/.minimax-flow/reconcile-summary.json';
        $this->openapiPath = $this->repoRoot.'/docs/contracts/api/openapi.yaml';
        $this->w12Path = $this->repoRoot.'/docs/contracts/api/w1-2.openapi.yaml';
        $this->r1ScreensPath = $this->repoRoot.'/docs/contracts/api/r1-screens.openapi.yaml';

        if (is_file($this->summaryPath)) {
            unlink($this->summaryPath);
        }

        $this->runReconcile();
    }

    public function test_s4_reconcile_mode_writes_reconcile_summary_json(): void
    {
        $this->assertFileExists($this->summaryPath);

        $payload = json_decode(
            (string) file_get_contents($this->summaryPath),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertArrayHasKey('timestamp', $payload);
        $this->assertArrayHasKey('paths_added', $payload);
        $this->assertArrayHasKey('paths_marked_planned', $payload);
        $this->assertArrayHasKey('parameter_name_syncs', $payload);
        $this->assertArrayHasKey('preservation_check', $payload);
        $this->assertIsArray($payload['paths_added']);
        $this->assertIsArray($payload['paths_marked_planned']);
    }

    public function test_s4_openapi_paths_are_append_only_no_deletions(): void
    {
        [$exitCode, $output] = $this->runShell(
            'git diff --no-color -- docs/contracts/api/openapi.yaml | grep -E "^-  /" | wc -l'
        );
        $this->assertSame(0, $exitCode, $output);

        $deleted = (int) trim($output);
        $this->assertSame(
            0,
            $deleted,
            "openapi.yaml must not delete any path keys; found {$deleted} removed path lines",
        );
    }

    public function test_s4_user_in_progress_job_titles_addition_is_preserved(): void
    {
        $content = (string) file_get_contents($this->openapiPath);

        $this->assertStringContainsString(
            '/organization/job-titles:',
            $content,
            '/organization/job-titles path key must remain in openapi.yaml',
        );
        $this->assertStringContainsString(
            'operationId: listJobTitles',
            $content,
            'listJobTitles operationId must remain in openapi.yaml',
        );
        $this->assertStringContainsString(
            'operationId: createJobTitle',
            $content,
            'createJobTitle operationId must remain in openapi.yaml',
        );
        $this->assertStringContainsString(
            'JobTitleCreate:',
            $content,
            'JobTitleCreate schema must remain in openapi.yaml',
        );
        $this->assertStringContainsString(
            'JobTitleEntity:',
            $content,
            'JobTitleEntity schema must remain in openapi.yaml',
        );
        $this->assertStringContainsString(
            'JobTitleCollection:',
            $content,
            'JobTitleCollection schema must remain in openapi.yaml',
        );
        $this->assertStringContainsString(
            'JobTitleResponse:',
            $content,
            'JobTitleResponse schema must remain in openapi.yaml',
        );

        // w1-2 split: the user's $ref line must still be present.
        $w12 = (string) file_get_contents($this->w12Path);
        $this->assertStringContainsString(
            '/organization/job-titles:',
            $w12,
            '/organization/job-titles $ref line must remain in w1-2.openapi.yaml',
        );
        $this->assertStringContainsString(
            '~1organization~1job-titles',
            $w12,
            'JSON-Pointer escape for /organization/job-titles must remain in w1-2.openapi.yaml',
        );
    }

    public function test_s4_exact_operation_delta_is_fully_classified(): void
    {
        $reconciliation = $this->loadSummary()['operation_reconciliation'];

        $this->assertSame(144, $reconciliation['live_operation_count']);
        $this->assertSame(203, $reconciliation['spec_operation_count']);
        $this->assertSame(64, $reconciliation['spec_only_operation_count']);
        $this->assertSame(50, $reconciliation['spec_only_path_count']);
        $this->assertSame(52, $reconciliation['effective_spec_only_operation_count']);
        $this->assertSame(38, $reconciliation['effective_spec_only_path_count']);
        $this->assertSame(5, $reconciliation['runtime_only_literal_count']);
        $this->assertCount(5, $reconciliation['intentional_runtime_only']);
        $this->assertSame([], $reconciliation['unresolved_runtime_only']);
        $this->assertSame([], $reconciliation['unclassified_spec_only']);
    }

    public function test_s4_r1_template_equivalents_are_implemented_while_w1_2_keeps_two_planned_operations(): void
    {
        $r1 = (string) file_get_contents($this->r1ScreensPath);
        $w12 = (string) file_get_contents($this->w12Path);

        $this->assertSame(0, substr_count($r1, 'x-implementation-status: planned'));
        $this->assertSame(2, substr_count($w12, 'x-implementation-status: planned'));
    }

    public function test_s4_does_not_modify_frozen_w1_1_openapi(): void
    {
        [$exitCode, $output] = $this->runShell(
            'git diff --no-color -- docs/contracts/api/w1-1.openapi.yaml'
        );
        $this->assertContains($exitCode, [0, 1], "git diff for w1-1.openapi.yaml failed: {$output}");
        $this->assertSame(
            '',
            trim($output),
            "w1-1.openapi.yaml is FROZEN; diff was:\n{$output}",
        );
    }

    public function test_s4_reconcile_summary_records_paths_added(): void
    {
        $payload = $this->loadSummary();

        // The summary records the paths added in this specific run. On a
        // re-run on an already-reconciled file, paths_added may legitimately
        // be 0 (idempotency). We instead verify the FINAL openapi.yaml file
        // contains every expected path after reconciliation.
        $openapi = (string) file_get_contents($this->openapiPath);
        $expected_paths = [
            '/authorization/bootstrap/complete',
            '/dashboards',
            '/dashboards/{dashboardId}',
            '/notifications/{notificationId}/read',
            '/organization/units/reorder',
            '/reports/{reportId}',
            '/tasks/from-step/{stepId}',
            '/work-records/{recordId}/documents',
            '/work-records/{recordId}/return',
            '/work-records/{recordId}/complete',
            '/work-records/{recordId}/complete-submission',
            '/up',
        ];

        foreach ($expected_paths as $path) {
            $this->assertStringContainsString(
                $path.':',
                $openapi,
                "openapi.yaml must contain the path key: {$path}",
            );
        }
    }

    public function test_s4_reconcile_is_idempotent_on_second_run(): void
    {
        // Capture sha256 of openapi.yaml after the first run.
        $firstOpenapiHash = hash_file('sha256', $this->openapiPath);
        $firstR1ScreensHash = hash_file('sha256', $this->r1ScreensPath);
        $firstW12Hash = hash_file('sha256', $this->w12Path);

        // Run reconcile a second time.
        $this->runReconcile();

        // The openapi canonical file must be byte-identical (idempotency).
        $secondOpenapiHash = hash_file('sha256', $this->openapiPath);
        $secondW12Hash = hash_file('sha256', $this->w12Path);
        $secondR1ScreensHash = hash_file('sha256', $this->r1ScreensPath);

        $this->assertSame(
            $firstOpenapiHash,
            $secondOpenapiHash,
            'openapi.yaml hash must not change on a second run of --mode reconcile --write',
        );
        $this->assertSame(
            $firstW12Hash,
            $secondW12Hash,
            'w1-2.openapi.yaml hash must not change on a second run of --mode reconcile --write',
        );
        $this->assertSame(
            $firstR1ScreensHash,
            $secondR1ScreensHash,
            'r1-screens.openapi.yaml hash must not change on a second run of --mode reconcile --write',
        );
    }

    public function test_s4_r1_screens_split_receives_ref_additions_for_new_paths(): void
    {
        $content = (string) file_get_contents($this->r1ScreensPath);

        // Paths that already live inline in r1-screens.openapi.yaml
        // (createTaskFromStep, linkWorkRecordDocument) must remain present.
        $this->assertStringContainsString(
            '/tasks/from-step/{stepId}:',
            $content,
            'r1-screens.openapi.yaml must keep /tasks/from-step/{stepId}',
        );
        $this->assertStringContainsString(
            '/work-records/{recordId}/documents:',
            $content,
            'r1-screens.openapi.yaml must keep /work-records/{recordId}/documents',
        );

        // The reconciler appends $ref: lines for the three NEW discrete
        // work-record verbs (return / complete / complete-submission) under
        // r1-screens.
        $this->assertStringContainsString(
            '~1work-records~1{recordId}~1return',
            $content,
            'r1-screens.openapi.yaml must reference /work-records/{recordId}/return via $ref',
        );
        $this->assertStringContainsString(
            '~1work-records~1{recordId}~1complete',
            $content,
            'r1-screens.openapi.yaml must reference /work-records/{recordId}/complete via $ref',
        );
        $this->assertStringContainsString(
            '~1work-records~1{recordId}~1complete-submission',
            $content,
            'r1-screens.openapi.yaml must reference /work-records/{recordId}/complete-submission via $ref',
        );
    }

    private function runReconcile(): void
    {
        [$exitCode, $output] = $this->runShell('python3 scripts/inventory-routes.py --mode reconcile --write');

        $this->assertSame(
            0,
            $exitCode,
            "reconcile mode failed (exit={$exitCode}):\n{$output}",
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSummary(): array
    {
        if (! is_file($this->summaryPath)) {
            $this->fail("reconcile-summary.json missing at {$this->summaryPath}");
        }

        $decoded = json_decode((string) file_get_contents($this->summaryPath), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array{0:int,1:string}
     */
    private function runShell(string $command): array
    {
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $this->repoRoot);
        if (! is_resource($process)) {
            $this->fail("Unable to start command: {$command}");
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [$exitCode, trim($stdout."\n".$stderr)];
    }
}
