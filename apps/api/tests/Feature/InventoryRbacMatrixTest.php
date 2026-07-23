<?php

namespace Tests\Feature;

use Tests\TestCase;

require_once __DIR__.'/../../../../scripts/rbac_helpers.php';

class InventoryRbacMatrixTest extends TestCase
{
    public function test_s2_inventory_script_emits_rbac_matrix_in_json_mode(): void
    {
        $tmp = $this->inventoryDir('s2-json');
        $this->deleteMatrixFile();

        [$exitCode, $output] = $this->runCommand(
            sprintf('python3 scripts/inventory-routes.py --mode rbac --json %s', escapeshellarg($tmp))
        );

        $this->assertSame(0, $exitCode, $output);
        $this->assertFileExists($tmp.'/rbac-matrix.json');
        $payload = json_decode(file_get_contents($tmp.'/rbac-matrix.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('rows', $payload);
        $this->assertArrayHasKey('middleware_tuples', $payload);
        $this->assertCount(119, $payload['rows']);
        $this->assertGreaterThanOrEqual(5, count($payload['middleware_tuples']));
        $this->assertLessThanOrEqual(8, count($payload['middleware_tuples']));
    }

    public function test_s2_internal_routes_carry_internal_worker_security_warning(): void
    {
        $this->deleteMatrixFile();
        [$exitCode, $output] = $this->runCommand('python3 scripts/inventory-routes.py --mode rbac --json /tmp/inventory-s2-internal');

        $this->assertSame(0, $exitCode, $output);
        $matrix = $this->loadMatrix();

        $internalScan = collect_rows($matrix['rows'], 'api-v1-internal-documents-versions-versionId-scan:post:scandocumentversioncontroller');
        $this->assertNotEmpty($internalScan, 'internal scan route missing');
        $row = $internalScan[0];
        $this->assertSame('60,1', $row['throttle']);
        $this->assertSame('internal-worker', $row['security_warning']);
        $this->assertContains('throttle:60,1', $row['middleware']);
    }

    public function test_s2_inline_csrf_routes_union_parent_middleware(): void
    {
        $this->deleteMatrixFile();
        [$exitCode] = $this->runCommand('python3 scripts/inventory-routes.py --mode rbac --json /tmp/inventory-s2-inline');

        $this->assertSame(0, $exitCode);
        $matrix = $this->loadMatrix();

        $rows = collect_rows($matrix['rows'], 'api-v1-organization-cluster:post:createclustercontroller');
        $this->assertNotEmpty($rows, 'cluster-create route missing');
        $this->assertTrue($rows[0]['requires_csrf']);

        $rows = collect_rows($matrix['rows'], 'api-v1-notifications-notificationId-read:post:marknotificationreadcontroller');
        $this->assertNotEmpty($rows, 'mark-read route missing');
        $this->assertTrue($rows[0]['requires_csrf']);
    }

    public function test_s2_work_record_lifecycle_inherits_csrf_through_parent_group(): void
    {
        $this->deleteMatrixFile();
        [$exitCode] = $this->runCommand('python3 scripts/inventory-routes.py --mode rbac --json /tmp/inventory-s2-work-records');

        $this->assertSame(0, $exitCode);
        $matrix = $this->loadMatrix();

        $submit = collect_rows($matrix['rows'], 'api-v1-work-records-recordId-recordAction-transition:post:workrecordlifecyclecontroller::transition');
        $this->assertNotEmpty($submit, 'work-records lifecycle submit missing');
        $this->assertTrue($submit[0]['requires_csrf']);
        $this->assertContains('project_work_record_read_models', $submit[0]['middleware']);
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

    private function deleteMatrixFile(): void
    {
        $paths = [
            '/tmp/inventory-s2-internal/rbac-matrix.json',
            '/tmp/inventory-s2-inline/rbac-matrix.json',
            '/tmp/inventory-s2-work-records/rbac-matrix.json',
        ];
        foreach ($paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function loadMatrix(): array
    {
        $candidates = [
            '/tmp/inventory-s2-internal/rbac-matrix.json',
            '/tmp/inventory-s2-inline/rbac-matrix.json',
            '/tmp/inventory-s2-work-records/rbac-matrix.json',
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            }
        }
        $this->fail('No rbac-matrix.json produced');
    }

    private function inventoryDir(string $slug): string
    {
        return '/tmp/inventory-'.$slug;
    }
}

/**
 * @param  array<int, array<string, mixed>>  $rows
 * @return array<int, array<string, mixed>>
 */
function collect_rows(array $rows, string $tag): array
{
    return array_values(array_filter($rows, fn ($row) => ($row['endpoint_tag'] ?? null) === $tag));
}
