<?php

namespace Tests\Feature;

use Tests\TestCase;

class InventoryMarkdownTest extends TestCase
{
    public function test_s3_inventory_script_renders_the_markdown_skeleton(): void
    {
        $tmp = '/tmp/inventory-s3';
        if (is_file($tmp.'/endpoints.md')) {
            unlink($tmp.'/endpoints.md');
        }

        [$exitCode, $output] = $this->runCommand(
            sprintf('python3 scripts/inventory-routes.py --mode md --json %s', escapeshellarg($tmp))
        );

        $this->assertSame(0, $exitCode, $output);
        $this->assertFileExists($tmp.'/endpoints.md');

        $markdown = file_get_contents($tmp.'/endpoints.md');
        $this->assertIsString($markdown);
        $this->assertGreaterThanOrEqual(112, preg_match_all('/^### /m', $markdown));
        $this->assertSame(1, preg_match_all('/^## Error Catalog$/m', $markdown));
        $this->assertSame(1, preg_match_all('/^## Exports \/ Internal \/ Health$/m', $markdown));
        $this->assertGreaterThanOrEqual(1, substr_count($markdown, 'LinkDocumentController'));
        $this->assertGreaterThanOrEqual(100, substr_count($markdown, '{{AR:'));
        $this->assertStringContainsString('Spec-only operations: `63` across `49` paths', $markdown);
        $this->assertStringContainsString('Runtime-only literal declarations: `5`; intentional template equivalences: `5`; unresolved: `0`', $markdown);
        $this->assertStringContainsString('`POST /api/v1/platform-operations/backups`', $markdown);
        $this->assertStringContainsString('`platform_operations.backup.run`', $markdown);
    }

    /**
     * @return array{0:int,1:string}
     */
    private function runCommand(string $command): array
    {
        $cwd = realpath(base_path('../..')) ?: base_path('../..');
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $cwd);
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
