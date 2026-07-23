<?php

namespace App\Integrations\PlatformOperations;

use LogicException;
use Modules\PlatformSettings\Contracts\BackupOperationsGateway;
use Modules\PlatformSettings\Domain\BackupStatus;
use Symfony\Component\Process\Process;

final readonly class CommandBackupOperationsGateway implements BackupOperationsGateway
{
    /** @param array{runtime: array{enabled: bool}, commands: array{backup: list<string>, restore_validation: list<string>}, command_timeout_seconds: int} $configuration */
    public function __construct(private array $configuration) {}

    public function status(): BackupStatus
    {
        return new BackupStatus(
            status: self::commandsConfigured($this->configuration) ? 'available' : 'unconfigured',
            lastSuccessfulAt: null,
            lastFailedAt: null,
            lastValidationAt: null,
        );
    }

    public function requestBackup(string $operationId): void
    {
        $this->run('backup');
    }

    public function requestRestoreValidation(string $operationId, string $backupId): void
    {
        $this->run('restore_validation');
    }

    /** @param array<string, mixed> $configuration */
    public static function assertRuntimeConfiguration(array $configuration): void
    {
        if (! self::commandsConfigured($configuration)) {
            throw new LogicException('Platform operations runtime requires allow-listed backup and restore validation commands.');
        }
    }

    /** @param array<string, mixed> $configuration */
    public static function commandsConfigured(array $configuration): bool
    {
        foreach (['backup', 'restore_validation'] as $operation) {
            $command = $configuration['commands'][$operation] ?? null;
            if (! is_array($command) || count($command) !== 1 || ! is_string($command[0])) {
                return false;
            }
            $path = $command[0];
            if (! str_starts_with($path, '/') || str_contains($path, '..') || preg_match('/[\s;&|`$]/', $path) === 1 || ! is_executable($path)) {
                return false;
            }
        }

        return true;
    }

    private function run(string $operation): void
    {
        if (! self::commandsConfigured($this->configuration)) {
            throw new LogicException('Platform operations commands are not configured.');
        }

        /** @var list<string> $command */
        $command = $this->configuration['commands'][$operation];
        $process = new Process($command, timeout: (float) $this->configuration['command_timeout_seconds']);
        $process->mustRun();
    }
}
