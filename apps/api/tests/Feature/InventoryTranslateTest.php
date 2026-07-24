<?php

namespace Tests\Feature;

use Tests\TestCase;

class InventoryTranslateTest extends TestCase
{
    private string $repoRoot;

    private string $endpointsPath;

    private string $summaryPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repoRoot = realpath(base_path('../..')) ?: base_path('../..');
        $this->endpointsPath = $this->repoRoot.'/docs/api/endpoints.md';
        $this->summaryPath = $this->repoRoot.'/.minimax-flow/translate-summary.json';

        // Start each test from a pristine S3 markdown so the assertions about
        // the initial 143 placeholders are stable. The S3 generator writes the
        // file in-place; we don't need to mirror it anywhere.
        [$exitCode, $output] = $this->runShell(
            'python3 scripts/inventory-routes.py --mode md --json '.escapeshellarg(dirname($this->endpointsPath))
        );
        $this->assertSame(
            0,
            $exitCode,
            "failed to regenerate pristine endpoints.md:\n{$output}",
        );
    }

    public function test_s6_translate_mode_replaces_every_placeholder(): void
    {
        $before = (string) file_get_contents($this->endpointsPath);
        $this->assertSame(143, substr_count($before, '{{AR:'), 'precondition: 143 placeholders expected');

        [$exitCode, $output] = $this->runShell(
            'python3 scripts/inventory-routes.py --mode translate --md-path '.escapeshellarg($this->endpointsPath)
        );

        $this->assertSame(0, $exitCode, "translate mode failed:\n{$output}");

        $after = (string) file_get_contents($this->endpointsPath);
        $this->assertSame(0, substr_count($after, '{{AR:'), 'no {{AR: placeholders may remain after translation');
        $this->assertSame(143, substr_count($after, 'ملخص'), 'every card should expose an Arabic ملخص header');
    }

    public function test_s6_translate_mode_is_idempotent(): void
    {
        [$firstExit, $firstOutput] = $this->runShell(
            'python3 scripts/inventory-routes.py --mode translate --md-path '.escapeshellarg($this->endpointsPath)
        );
        $this->assertSame(0, $firstExit, "first translate run must succeed:\n{$firstOutput}");

        $afterFirst = (string) file_get_contents($this->endpointsPath);
        // Sanity: first run actually translated.
        $this->assertSame(0, substr_count($afterFirst, '{{AR:'), 'first run must replace placeholders');
        $this->assertSame(143, substr_count($afterFirst, 'ملخص'), 'first run must add Arabic headers');

        [$secondExit, $secondOutput] = $this->runShell(
            'python3 scripts/inventory-routes.py --mode translate --md-path '.escapeshellarg($this->endpointsPath)
        );
        $this->assertSame(0, $secondExit, "second translate run must succeed:\n{$secondOutput}");

        $afterSecond = (string) file_get_contents($this->endpointsPath);
        $this->assertSame($afterFirst, $afterSecond, 'second translate run must not modify the file');
    }

    public function test_s6_translate_mode_writes_summary_artifact(): void
    {
        if (is_file($this->summaryPath)) {
            unlink($this->summaryPath);
        }

        [$exitCode, $output] = $this->runShell(
            'python3 scripts/inventory-routes.py --mode translate --md-path '.escapeshellarg($this->endpointsPath)
        );

        $this->assertSame(0, $exitCode, "translate mode failed:\n{$output}");
        $this->assertFileExists($this->summaryPath);

        $payload = json_decode((string) file_get_contents($this->summaryPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('timestamp', $payload);
        $this->assertArrayHasKey('endpoint_count_translated', $payload);
        $this->assertSame(143, $payload['endpoint_count_translated']);
        $this->assertArrayHasKey('sample_arabic', $payload);
        $this->assertIsArray($payload['sample_arabic']);
        $this->assertGreaterThanOrEqual(5, count($payload['sample_arabic']));
        foreach (array_slice($payload['sample_arabic'], 0, 5) as $sample) {
            $this->assertIsString($sample);
            $this->assertNotSame('', $sample);
            // Each sample must contain at least one Arabic letter from the verb set.
            $this->assertMatchesRegularExpression(
                '/(تسترجع|تنشئ|تعدّل|تستبدل|تحذف|تنفذ)/u',
                $sample,
                "sample missing Arabic verb: {$sample}"
            );
        }
    }

    public function test_s6_existing_inventory_check_still_passes(): void
    {
        // Re-running translate must leave the route inventory invariants intact.
        [$exitCode, $output] = $this->runShell('python3 scripts/inventory-routes.py --check');

        $this->assertSame(0, $exitCode, "--check must keep exiting 0 after translation:\n{$output}");
        $this->assertStringContainsString('parsed=143', $output);
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
