<?php

namespace Modules\Organization\Infrastructure\Import;

use Modules\Organization\Contracts\ResolveQuarantinedImport;

final class UnavailableQuarantinedImport implements ResolveQuarantinedImport
{
    public function resolve(string $quarantineObjectId, string $templateCode, string $format): ?array
    {
        return null;
    }
}
