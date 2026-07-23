<?php

namespace Tests\Feature;

use Tests\TestCase;

class InventoryVerificationTest extends TestCase
{
    private const SCRIPT_PATH = 'scripts/verify-inventory.sh';

    private const REPORT_PATH = '.minimax-flow/verification-report.md';

    public function test_s7_verify_inventory_script_exists_and_is_executable(): void
    {
        $cwd = $this->repoRoot();
        $script = $cwd.'/'.self::SCRIPT_PATH;

        $this->assertFileExists($script, 'verify-inventory.sh must exist under scripts/');
        $this->assertFileIsReadable($script);

        $perms = fileperms($script);
        $this->assertNotFalse($perms);
        $this->assertSame(
            0o100,
            ($perms & 0o100),
            'verify-inventory.sh must be executable by the owner',
        );
    }

    public function test_s7_verify_inventory_script_produces_a_valid_report(): void
    {
        $report = $this->runScriptAndCaptureReport();

        $this->assertFileExists($report, 'verify-inventory.sh must produce '.self::REPORT_PATH);

        $content = file_get_contents($report);
        $this->assertIsString($content);

        $this->assertStringContainsString('# Tier-2 Integration Verification Report', $content);
        $this->assertStringContainsString('| Check | Expected | Actual | Pass/Fail | Evidence |', $content);

        foreach ($this->expectedChecks() as $needle) {
            $this->assertStringContainsString(
                $needle,
                $content,
                'verification report must mention check: '.$needle,
            );
        }

        $rows = $this->parseRows($content);
        $this->assertGreaterThanOrEqual(
            count($this->expectedChecks()),
            count($rows),
            'verification report must include at least one row per expected check',
        );

        $statusByCheck = $this->indexByCheck($rows);
        foreach ($this->expectedChecks() as $needle) {
            $row = $statusByCheck[$needle] ?? null;
            $this->assertNotNull($row, "missing row for check: {$needle}");
            $this->assertContains(
                $row['status'],
                ['PASS', 'FAIL'],
                "row status for {$needle} must be PASS or FAIL, got: {$row['status']}",
            );
        }

        unlink($report);
    }

    public function test_s7_verify_inventory_script_exit_reflects_failures(): void
    {
        [$exitCode, $output] = $this->runCommand(self::SCRIPT_PATH);

        $report = $this->repoRoot().'/'.self::REPORT_PATH;
        $this->assertFileExists($report);

        if ($exitCode === 0) {
            $this->assertStringContainsString('Tier-2 verification: PASS', $output);
        } else {
            $this->assertNotSame(
                0,
                $exitCode,
                'non-zero exit must indicate at least one failed check',
            );
        }

        unlink($report);
    }

    /**
     * @return list<string>
     */
    private function expectedChecks(): array
    {
        return [
            'make verify-boundaries',
            'git diff --stat apps/api apps/web',
            'inventory-routes.py --check (initial)',
            'npm api:lint',
            'rbac idempotency',
            'npm api:bundle',
            'validate-docs.sh',
            'inventory-routes.py --check (post md-write)',
            'endpoints.md line count',
            'endpoints.md section headings',
            'endpoints.md AR placeholder count',
        ];
    }

    private function runScriptAndCaptureReport(): string
    {
        $cwd = $this->repoRoot();
        $report = $cwd.'/'.self::REPORT_PATH;

        if (is_file($report)) {
            unlink($report);
        }

        [$exitCode] = $this->runCommand(self::SCRIPT_PATH);
        $this->assertFileExists($report, 'verify-inventory.sh must produce '.self::REPORT_PATH);

        return $report;
    }

    /**
     * @return array{0:int,1:string}
     */
    private function runCommand(string $relative): array
    {
        $cwd = $this->repoRoot();
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open('bash '.escapeshellarg($cwd.'/'.$relative), $descriptors, $pipes, $cwd);
        if (! is_resource($process)) {
            $this->fail("Unable to start command: {$relative}");
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [$exitCode, trim($stdout."\n".$stderr)];
    }

    private function repoRoot(): string
    {
        $cwd = realpath(base_path('../..'));
        $this->assertNotFalse($cwd);

        return $cwd;
    }

    /**
     * @return list<array{check:string,status:string}>
     */
    private function parseRows(string $content): array
    {
        $rows = [];
        $lines = preg_split('/\R/', $content);
        foreach ($lines as $line) {
            if (! str_starts_with($line, '|')) {
                continue;
            }
            $cells = array_map('trim', explode('|', $line));
            $cells = array_values(array_filter($cells, static fn (string $cell): bool => $cell !== ''));
            if (count($cells) < 4) {
                continue;
            }
            if ($cells[0] === 'Check') {
                continue;
            }
            if (preg_match('/^-+$/', $cells[0])) {
                continue;
            }
            $rows[] = [
                'check' => $cells[0],
                'expected' => $cells[1],
                'actual' => $cells[2],
                'status' => $cells[3],
                'evidence' => $cells[4] ?? '',
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{check:string,status:string}>  $rows
     * @return array<string, array{check:string,status:string}>
     */
    private function indexByCheck(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[$row['check']] = $row;
        }

        return $out;
    }
}
