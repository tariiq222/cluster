<?php

namespace Modules\Identity\Contracts;

use Illuminate\Http\Request;

/**
 * Resolves the trusted PrincipalContext from the validated Identity session.
 * Every failure mode fails closed to null; nothing here trusts client input.
 */
interface ResolvePrincipalContext
{
    public function resolve(Request $request): ?PrincipalContext;

    /** @return array{scope_type: string, scope_id: string}|null */
    public function resolveSelectedScope(Request $request): ?array;

    /**
     * Persists the caller's scope selection into the session row metadata,
     * preserving every other metadata key.
     */
    public function persistSelectedScope(Request $request, string $scopeType, string $scopeId): void;
}
