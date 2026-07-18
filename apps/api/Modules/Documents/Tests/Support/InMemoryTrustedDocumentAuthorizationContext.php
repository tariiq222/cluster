<?php

namespace Modules\Documents\Tests\Support;

use DomainException;
use Modules\Documents\Contracts\TrustedDocumentAuthorizationContext;

final readonly class InMemoryTrustedDocumentAuthorizationContext implements TrustedDocumentAuthorizationContext
{
    /** @param list<string> $allowedOperations */
    public function __construct(
        private string $principal,
        private string $organizationUnit,
        private string $context,
        private array $allowedOperations,
    ) {}

    public function principalId(): string
    {
        return $this->principal;
    }

    public function organizationUnitId(): string
    {
        return $this->organizationUnit;
    }

    public function authorizationContextId(): string
    {
        return $this->context;
    }

    public function assertAuthorized(string $operation): void
    {
        if (! in_array($operation, $this->allowedOperations, true)) {
            throw new DomainException('document_authorization_denied');
        }
    }
}
