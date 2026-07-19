<?php

namespace Modules\WorkDefinitions\Contracts;

interface ResolvePublishedWorkDefinition
{
    /** @return array{version_id: string, code: string, fields: list<string>, classification: string}|null */
    public function resolve(string $code): ?array;
}
