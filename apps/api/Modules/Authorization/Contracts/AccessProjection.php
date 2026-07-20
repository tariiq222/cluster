<?php

namespace Modules\Authorization\Contracts;

/**
 * Server-owned access metadata carried with an authorized resource projection.
 *
 * This object deliberately contains no resource data and cannot be constructed
 * from request input. Callers compose its output with data filtered by the
 * owning module.
 */
final readonly class AccessProjection
{
    /**
     * @param  list<string>  $allowedActions
     * @param  array<string, 'hidden'|'masked'|'readonly'|'editable'>  $fieldAccess
     */
    public function __construct(
        public ?string $decisionId,
        public array $allowedActions,
        public array $fieldAccess,
    ) {}

    public static function fromDecision(AccessDecision $decision): self
    {
        return new self(
            decisionId: $decision->decisionId,
            allowedActions: array_values(array_unique($decision->allowedActions)),
            fieldAccess: $decision->fieldAccess,
        );
    }

    /**
     * @param  array<string, mixed>  $resource
     * @param  (callable(array<string, mixed>, array<string, string>): array<string, mixed>)|null  $filterPayload
     * @return array<string, mixed>
     */
    public function compose(array $resource, ?callable $filterPayload = null): array
    {
        if ($filterPayload !== null && array_key_exists('payload', $resource) && is_array($resource['payload'])) {
            $resource['payload'] = $filterPayload($resource['payload'], $this->fieldAccess);
        }

        return [
            ...$resource,
            'allowed_actions' => $this->allowedActions,
            'field_access' => (object) $this->fieldAccess,
            'decision_id' => $this->decisionId,
        ];
    }
}
