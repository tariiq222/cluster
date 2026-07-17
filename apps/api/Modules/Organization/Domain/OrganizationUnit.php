<?php

namespace Modules\Organization\Domain;

use InvalidArgumentException;

final readonly class OrganizationUnit
{
    private function __construct(
        public string $id,
        public string $clusterId,
        public string $parentId,
        public string $parentType,
        public string $typeCode,
        public string $code,
        public string $nameAr,
        public ?string $nameEn,
        public string $pathCache,
        public int $depth,
    ) {}

    public static function create(
        string $id,
        string $clusterId,
        string $parentId,
        string $parentType,
        string $typeCode,
        string $code,
        string $nameAr,
        ?string $nameEn,
        string $pathCache,
        int $depth,
    ): self {
        foreach ([$id, $clusterId, $parentId] as $uuid) {
            if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) !== 1) {
                throw new InvalidArgumentException('Organization unit identifiers must be lowercase UUIDv7 values.');
            }
        }
        if (! in_array($parentType, ['cluster', 'facility', 'unit'], true)
            || preg_match('/\A[a-z][a-z0-9_]{1,63}\z/', $typeCode) !== 1
            || preg_match('/\A[A-Z0-9_-]{2,64}\z/', $code) !== 1
            || $nameAr === ''
            || mb_strlen($nameAr) > 255
            || ($nameEn !== null && mb_strlen($nameEn) > 255)
            || $depth < 1
            || strlen($pathCache) > 512) {
            throw new InvalidArgumentException('Organization unit data is invalid.');
        }

        return new self($id, $clusterId, $parentId, $parentType, $typeCode, $code, $nameAr, $nameEn, $pathCache, $depth);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'cluster_id' => $this->clusterId,
            'parent_id' => $this->parentId,
            'parent_type' => $this->parentType,
            'type_code' => $this->typeCode,
            'code' => $this->code,
            'name_ar' => $this->nameAr,
            'name_en' => $this->nameEn,
            'status' => 'active',
            'path_cache' => $this->pathCache,
            'depth' => $this->depth,
            'lock_version' => 1,
        ];
    }
}
