<?php

namespace Modules\Documents\Infrastructure\Authorization;

use DomainException;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Documents\Contracts\TrustedDocumentAuthorizationContext;
use Modules\Documents\Domain\UuidV7;

/** Converts a trusted authenticated principal plus an allow decision into Documents' boundary. */
final readonly class GrantedDocumentAuthorizationContext implements TrustedDocumentAuthorizationContext
{
    private function __construct(
        private string $principalId,
        private string $organizationUnitId,
        private string $contextId,
        private string $operation,
    ) {}

    /** @param array{user_id: string, facility_id: string} $principal */
    public static function fromGrantedDecision(
        array $principal,
        string $correlationId,
        AccessDecision $decision,
        string $operation,
    ): self {
        if (! $decision->isAllowed() || ! hash_equals($operation, $decision->action)) {
            throw new DomainException('document_authorization_denied');
        }
        UuidV7::assert($principal['user_id'], 'Authenticated document principal id');
        UuidV7::assert($principal['facility_id'], 'Authenticated document organization unit id');
        UuidV7::assert($correlationId, 'Document authorization context id');

        return new self($principal['user_id'], $principal['facility_id'], $correlationId, $operation);
    }

    public function principalId(): string
    {
        return $this->principalId;
    }

    public function organizationUnitId(): string
    {
        return $this->organizationUnitId;
    }

    public function authorizationContextId(): string
    {
        return $this->contextId;
    }

    public function assertAuthorized(string $operation): void
    {
        if (! hash_equals($this->operation, $operation)) {
            throw new DomainException('document_authorization_denied');
        }
    }
}
