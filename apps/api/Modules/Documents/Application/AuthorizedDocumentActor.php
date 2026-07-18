<?php

namespace Modules\Documents\Application;

use DomainException;
use Modules\Documents\Contracts\TrustedDocumentAuthorizationContext;
use Modules\Documents\Domain\UuidV7;

final readonly class AuthorizedDocumentActor
{
    private function __construct(
        public string $principalId,
        public string $organizationUnitId,
        public string $authorizationContextId,
        private string $operation,
    ) {}

    public static function fromTrustedContext(TrustedDocumentAuthorizationContext $context, string $operation): self
    {
        $context->assertAuthorized($operation);
        UuidV7::assert($context->principalId(), 'Authorized document actor principal id');
        UuidV7::assert($context->organizationUnitId(), 'Authorized document actor organization unit id');
        UuidV7::assert($context->authorizationContextId(), 'Authorization context id');

        return new self(
            $context->principalId(),
            $context->organizationUnitId(),
            $context->authorizationContextId(),
            $operation,
        );
    }

    public function assertBoundTo(string $operation, string $ownerOrganizationUnitId): void
    {
        if ($this->operation !== $operation) {
            throw new DomainException('document_authorization_operation_mismatch');
        }
        if (! hash_equals($this->organizationUnitId, $ownerOrganizationUnitId)) {
            throw new DomainException('document_owner_organization_mismatch');
        }
    }
}
