<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class InventoryRbacMatrixTest extends TestCase
{
    public function test_s2_inventory_script_emits_rbac_matrix_in_json_mode(): void
    {
        $tmp = sys_get_temp_dir().'/rbac-inv-'.uniqid();
        @mkdir($tmp);

        $repoRoot = dirname(dirname(base_path()));
        $cmd = sprintf(
            'cd %s && python3 scripts/inventory-routes.py --mode rbac --json %s 2>&1',
            escapeshellarg($repoRoot),
            escapeshellarg($tmp),
        );
        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertFileExists($tmp.'/rbac-matrix.json');
        $payload = json_decode(file_get_contents($tmp.'/rbac-matrix.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('rows', $payload);
        $this->assertArrayHasKey('middleware_tuples', $payload);
        $this->assertNotEmpty($payload['rows']);
    }
}
