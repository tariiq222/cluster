<?php

namespace Modules\Organization\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class SupervisoryRelationship
{
    /** @var list<string> */
    private const TYPES = ['direct', 'functional', 'coordination', 'read_only'];

    /** @param list<RelationshipCapability> $capabilities */
    private function __construct(
        public string $id,
        public string $sourceOrganizationUnitId,
        public string $targetOrganizationUnitId,
        public string $relationshipType,
        public DateTimeImmutable $validFrom,
        public DateTimeImmutable $validUntil,
        public array $capabilities,
    ) {}

    /** @param list<RelationshipCapability> $capabilities */
    public static function create(
        string $id,
        string $sourceOrganizationUnitId,
        string $targetOrganizationUnitId,
        string $relationshipType,
        DateTimeImmutable $validFrom,
        DateTimeImmutable $validUntil,
        array $capabilities = [],
    ): self {
        foreach ([$id, $sourceOrganizationUnitId, $targetOrganizationUnitId] as $identifier) {
            if (! self::isUuidV7($identifier)) {
                throw new InvalidArgumentException('supervisory_relationship_identifiers_invalid');
            }
        }
        if (! in_array($relationshipType, self::TYPES, true)) {
            throw new InvalidArgumentException('supervisory_relationship_type_invalid');
        }

        $utc = new DateTimeZone('UTC');
        $validFrom = $validFrom->setTimezone($utc);
        $validUntil = $validUntil->setTimezone($utc);
        if ($validUntil <= $validFrom) {
            throw new InvalidArgumentException('supervisory_relationship_period_invalid');
        }
        foreach ($capabilities as $capability) {
            if (! $capability instanceof RelationshipCapability
                || $capability->supervisoryRelationshipId !== $id) {
                throw new InvalidArgumentException('supervisory_relationship_capability_invalid');
            }
        }

        return new self(
            $id,
            $sourceOrganizationUnitId,
            $targetOrganizationUnitId,
            $relationshipType,
            $validFrom,
            $validUntil,
            $capabilities,
        );
    }

    public function isActiveAt(DateTimeImmutable $at): bool
    {
        $at = $at->setTimezone(new DateTimeZone('UTC'));

        return $at >= $this->validFrom && $at < $this->validUntil;
    }

    /**
     * @return array{
     *     supervisory_relationship_id: string,
     *     source_organization_unit_id: string,
     *     target_organization_unit_id: string,
     *     relationship_type: string,
     *     valid_from: string,
     *     valid_until: string,
     *     relationship_capabilities: list<array{relationship_capability_id: string, module_code: string, capability_code: string}>
     * }|null
     */
    public function activeFactAt(DateTimeImmutable $at): ?array
    {
        return $this->isActiveAt($at) ? $this->toFact() : null;
    }

    /**
     * @return array{
     *     supervisory_relationship_id: string,
     *     source_organization_unit_id: string,
     *     target_organization_unit_id: string,
     *     relationship_type: string,
     *     valid_from: string,
     *     valid_until: string,
     *     relationship_capabilities: list<array{relationship_capability_id: string, module_code: string, capability_code: string}>
     * }
     */
    public function toFact(): array
    {
        return [
            'supervisory_relationship_id' => $this->id,
            'source_organization_unit_id' => $this->sourceOrganizationUnitId,
            'target_organization_unit_id' => $this->targetOrganizationUnitId,
            'relationship_type' => $this->relationshipType,
            'valid_from' => $this->timestamp($this->validFrom),
            'valid_until' => $this->timestamp($this->validUntil),
            'relationship_capabilities' => array_map(
                static fn (RelationshipCapability $capability): array => $capability->toFact(),
                $this->capabilities,
            ),
        ];
    }

    /** @return array{id: string, source_organization_unit_id: string, target_organization_unit_id: string, relationship_type: string, valid_from: string, valid_until: string} */
    public function toPersistence(): array
    {
        return [
            'id' => $this->id,
            'source_organization_unit_id' => $this->sourceOrganizationUnitId,
            'target_organization_unit_id' => $this->targetOrganizationUnitId,
            'relationship_type' => $this->relationshipType,
            'valid_from' => $this->databaseTimestamp($this->validFrom),
            'valid_until' => $this->databaseTimestamp($this->validUntil),
        ];
    }

    private static function isUuidV7(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) === 1;
    }

    private function timestamp(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d\TH:i:s.v\Z');
    }

    private function databaseTimestamp(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.v');
    }
}
