<?php

namespace Modules\Identity\Features\Sessions\Contracts;

interface ResolveSession
{
    /** @return array{user_id: string, session_id: string, csrf_token_hash: string|null, restricted: bool}|null */
    public function resolve(string $rawSessionToken, TrustedRequestBindingContext $context): ?array;

    public function validateCsrf(string $rawSessionToken, string $rawCsrfToken, TrustedRequestBindingContext $context): bool;
}
