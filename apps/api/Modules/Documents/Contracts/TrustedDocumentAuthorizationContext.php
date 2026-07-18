<?php

namespace Modules\Documents\Contracts;

/**
 * Adapter boundary for a decision already issued by Authorization. Documents
 * never accepts an untrusted principal or organization identifier directly.
 */
interface TrustedDocumentAuthorizationContext
{
    public function principalId(): string;

    public function organizationUnitId(): string;

    public function authorizationContextId(): string;

    public function assertAuthorized(string $operation): void;
}
