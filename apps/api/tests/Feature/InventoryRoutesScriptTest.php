<?php

namespace Tests\Feature;

use Tests\TestCase;

class InventoryRoutesScriptTest extends TestCase
{
    public function test_s1_make_target_api_inventory_exists(): void
    {
        [$exitCode, $output] = $this->runCommand('make -n api:inventory');

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('scripts/inventory-routes.py', $output);
        $this->assertStringContainsString('--check', $output);
    }

    public function test_s1_inventory_script_reports_the_live_route_count(): void
    {
        [$exitCode, $output] = $this->runCommand('python3 scripts/inventory-routes.py --check');

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('parsed=150', $output);
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
