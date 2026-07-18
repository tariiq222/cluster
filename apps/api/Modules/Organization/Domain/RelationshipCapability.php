<?php

namespace Modules\Organization\Domain;

use InvalidArgumentException;

final readonly class RelationshipCapability
{
    private function __construct(
        public string $id,
        public string $supervisoryRelationshipId,
        public string $moduleCode,
        public string $capabilityCode,
    ) {}

    public static function create(
        string $id,
        string $supervisoryRelationshipId,
        string $moduleCode,
        string $capabilityCode,
    ): self {
        foreach ([$id, $supervisoryRelationshipId] as $identifier) {
            if (! self::isUuidV7($identifier)) {
                throw new InvalidArgumentException('relationship_capability_identifiers_invalid');
            }
        }
        if (! self::isModuleCode($moduleCode) || ! self::isCapabilityCode($capabilityCode)) {
            throw new InvalidArgumentException('relationship_capability_code_invalid');
        }

        return new self($id, $supervisoryRelationshipId, $moduleCode, $capabilityCode);
    }

    /** @return array{relationship_capability_id: string, module_code: string, capability_code: string} */
    public function toFact(): array
    {
        return [
            'relationship_capability_id' => $this->id,
            'module_code' => $this->moduleCode,
            'capability_code' => $this->capabilityCode,
        ];
    }

    /** @return array{id: string, supervisory_relationship_id: string, module_code: string, capability_code: string} */
    public function toPersistence(): array
    {
        return [
            'id' => $this->id,
            'supervisory_relationship_id' => $this->supervisoryRelationshipId,
            'module_code' => $this->moduleCode,
            'capability_code' => $this->capabilityCode,
        ];
    }

    private static function isUuidV7(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) === 1;
    }

    private static function isModuleCode(string $value): bool
    {
        return preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $value) === 1;
    }

    private static function isCapabilityCode(string $value): bool
    {
        return preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/', $value) === 1;
    }
}
