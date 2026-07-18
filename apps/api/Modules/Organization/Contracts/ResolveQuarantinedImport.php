<?php

namespace Modules\Organization\Contracts;

interface ResolveQuarantinedImport
{
    /** @return array{source_filename: string, rows: list<array<string, mixed>>}|null */
    public function resolve(string $quarantineObjectId, string $templateCode, string $format): ?array;
}
