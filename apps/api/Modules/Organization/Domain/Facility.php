<?php

namespace Modules\Organization\Domain;

use InvalidArgumentException;

final readonly class Facility
{
    private function __construct(
        public string $id,
        public string $clusterId,
        public string $typeCode,
        public string $code,
        public string $nameAr,
        public ?string $nameEn,
    ) {}

    public static function create(
        string $id,
        string $clusterId,
        string $typeCode,
        string $code,
        string $nameAr,
        ?string $nameEn,
    ): self {
        self::assertUuidV7($id, 'Facility id');
        self::assertUuidV7($clusterId, 'Cluster id');
        if (preg_match('/\A[a-z][a-z0-9_]{1,63}\z/', $typeCode) !== 1
            || preg_match('/\A[A-Z0-9_-]{2,64}\z/', $code) !== 1) {
            throw new InvalidArgumentException('Facility type or code is invalid.');
        }
        if ($nameAr === '' || mb_strlen($nameAr) > 255 || ($nameEn !== null && mb_strlen($nameEn) > 255)) {
            throw new InvalidArgumentException('Facility name is invalid.');
        }

        return new self($id, $clusterId, $typeCode, $code, $nameAr, $nameEn);
    }

    /** @return array{id: string, cluster_id: string, type_code: string, code: string, name_ar: string, name_en: ?string, status: string, lock_version: int} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'cluster_id' => $this->clusterId,
            'type_code' => $this->typeCode,
            'code' => $this->code,
            'name_ar' => $this->nameAr,
            'name_en' => $this->nameEn,
            'status' => 'active',
            'lock_version' => 1,
        ];
    }

    private static function assertUuidV7(string $id, string $field): void
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $id) !== 1) {
            throw new InvalidArgumentException("{$field} must be a lowercase UUIDv7.");
        }
    }
}
