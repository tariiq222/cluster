<?php

namespace Tests\Feature;

use Tests\TestCase;

class InventoryBundleTest extends TestCase
{
    private string $repoRoot;

    private string $summaryPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repoRoot = realpath(base_path('../..')) ?: base_path('../..');
        $this->summaryPath = $this->repoRoot . '/.minimax-flow/bundle-summary.json';

        if (is_file($this->summaryPath)) {
            unlink($this->summaryPath);
        }

        $this->runReconcile();
    }

    public function test_s5_reconcile_mode_writes_bundle_summary_json(): void
    {
        $this->assertFileExists($this->summaryPath);

        $payload = json_decode((string) file_get_contents($this->summaryPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('timestamp', $payload);
        $this->assertArrayHasKey('bundles', $payload);
        $this->assertArrayHasKey('split_source_diffs', $payload);

        $bundles = $payload['bundles'];
        $this->assertArrayHasKey('cluster.openapi.yaml', $bundles);
        $this->assertArrayHasKey('cluster-w1-2.openapi.yaml', $bundles);
        $this->assertArrayHasKey('cluster-r1-screens.openapi.yaml', $bundles);

        foreach (['cluster.openapi.yaml', 'cluster-w1-2.openapi.yaml', 'cluster-r1-screens.openapi.yaml'] as $key) {
            $bundle = $bundles[$key];
            $this->assertArrayHasKey('source', $bundle);
            $this->assertArrayHasKey('pre_size', $bundle);
            $this->assertArrayHasKey('post_size', $bundle);
            $this->assertArrayHasKey('pre_sha256', $bundle);
            $this->assertArrayHasKey('post_sha256', $bundle);
            $this->assertArrayHasKey('path_count', $bundle);
        }

        $this->assertTrue($bundles['cluster.openapi.yaml']['frozen'] ?? false);
        $this->assertFalse($bundles['cluster-w1-2.openapi.yaml']['frozen'] ?? true);
        $this->assertFalse($bundles['cluster-r1-screens.openapi.yaml']['frozen'] ?? true);
    }

    public function test_s5_frozen_split_w1_1_has_empty_git_diff(): void
    {
        $diff = $this->captureGitDiff('docs/contracts/api/w1-1.openapi.yaml');
        $this->assertSame('', trim($diff), "w1-1 split should be frozen; got diff:\n{$diff}");
    }

    public function test_s5_w1_2_split_is_append_only_ref_additions(): void
    {
        $this->assertAppendOnlyRefAdditions('docs/contracts/api/w1-2.openapi.yaml');
    }

    public function test_s5_r1_screens_split_is_append_only_ref_additions(): void
    {
        $this->assertAppendOnlyRefAdditions('docs/contracts/api/r1-screens.openapi.yaml');
    }

    public function test_s5_cluster_openapi_bundle_sha_unchanged_after_reconcile(): void
    {
        $current = sha256_of($this->repoRoot . '/apps/web/.orval/cluster.openapi.yaml');
        $payload = $this->loadSummary();
        $pre = $payload['bundles']['cluster.openapi.yaml']['pre_sha256'];
        $post = $payload['bundles']['cluster.openapi.yaml']['post_sha256'];

        $this->assertSame($pre, $current, 'cluster.openapi.yaml SHA drifted from pre-run snapshot');
        $this->assertSame($pre, $post, 'cluster.openapi.yaml should be deterministic across re-bundles');
    }

    public function test_s5_w1_2_and_r1_screens_bundles_refreshed(): void
    {
        $payload = $this->loadSummary();
        $w12 = $payload['bundles']['cluster-w1-2.openapi.yaml'];
        $r1 = $payload['bundles']['cluster-r1-screens.openapi.yaml'];

        $this->assertGreaterThanOrEqual($w12['pre_size'], $w12['post_size']);
        $this->assertGreaterThanOrEqual($r1['pre_size'], $r1['post_size']);
        $this->assertGreaterThan(0, $w12['path_count']);
        $this->assertGreaterThan(0, $r1['path_count']);
    }

    private function runReconcile(): void
    {
        $command = 'python3 scripts/inventory-routes.py --mode reconcile --write --bundle';
        [$exitCode, $output] = $this->runShell($command);

        $this->assertSame(
            0,
            $exitCode,
            "reconcile mode failed (exit={$exitCode}):\n{$output}",
        );
    }

    private function loadSummary(): array
    {
        if (! is_file($this->summaryPath)) {
            $this->fail("bundle-summary.json missing at {$this->summaryPath}");
        }

        return json_decode((string) file_get_contents($this->summaryPath), true, flags: JSON_THROW_ON_ERROR);
    }

    private function captureGitDiff(string $relPath): string
    {
        $command = sprintf('git diff --no-color -- %s', escapeshellarg($relPath));
        [$exitCode, $output] = $this->runShell($command);

        // exit 0 = no diff; 1 = diff present; we treat both as 'capture output'.
        $this->assertContains($exitCode, [0, 1], "git diff failed: {$output}");

        return $output;
    }

    private function assertAppendOnlyRefAdditions(string $relPath): void
    {
        $diff = $this->captureGitDiff($relPath);

        if (trim($diff) === '') {
            $this->assertTrue(true);

            return;
        }

        $payload = $this->loadSummary()['split_source_diffs'][$relPath] ?? null;
        $this->assertNotNull($payload, "split_source_diffs missing entry for {$relPath}");
        $this->assertTrue($payload['ok'] ?? false, "diff for {$relPath} not flagged ok: " . json_encode($payload));
        $this->assertTrue($payload['all_ref_additions'] ?? false, "diff for {$relPath} contains non-ref additions: " . json_encode($payload));
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

        return [$exitCode, trim($stdout . "\n" . $stderr)];
    }
}

/**
 * @internal Used inside InventoryBundleTest only; exposed globally for the
 *           test file to avoid pulling in bundle_runner.py at unit-test time.
 */
function sha256_of(string $path): string
{
    return hash_file('sha256', $path);
}
