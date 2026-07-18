<?php

namespace Modules\Organization\Features\ImportJob\Template;

use Closure;

interface GovernedImportTemplate
{
    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{code: string, severity: string, field?: string}>
     */
    public function validate(array $payload): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{code: string, severity: string, field?: string}>
     */
    public function validateBatch(array $payload, ImportBatchContext $context, int $rowNumber): array;

    /**
     * @param  array<string, mixed>  $payload
     * @param  Closure(string, string, array<string, mixed>, string): array<string, mixed>  $eventFactory
     * @param  Closure(string, array<string, mixed>): array{principal_id: string, operation: string, key_hash: string, request_hash: string}  $idempotencyFactory
     */
    public function apply(
        string $rowId,
        array $payload,
        string $principalId,
        Closure $eventFactory,
        Closure $idempotencyFactory,
    ): string;
}
