<?php

namespace Modules\PlatformSettings\Contracts;

use Modules\PlatformSettings\Domain\BackupStatus;

interface BackupOperationsGateway
{
    public function status(): BackupStatus;

    public function requestBackup(string $operationId): void;

    public function requestRestoreValidation(string $operationId, string $backupId): void;
}
