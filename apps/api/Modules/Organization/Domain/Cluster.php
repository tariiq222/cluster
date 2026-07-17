<?php

namespace Modules\Organization\Domain;

use InvalidArgumentException;

final readonly class Cluster
{
    private function __construct(
        public string $id,
        public string $code,
        public string $nameAr,
        public ?string $nameEn,
    ) {}

    public static function create(string $id, string $code, string $nameAr, ?string $nameEn): self
    {
        self::assertUuidV7($id);
        if (preg_match('/\A[A-Z0-9_-]{2,64}\z/', $code) !== 1) {
            throw new InvalidArgumentException('Cluster code is invalid.');
        }
        if ($nameAr === '' || mb_strlen($nameAr) > 255 || ($nameEn !== null && mb_strlen($nameEn) > 255)) {
            throw new InvalidArgumentException('Cluster name is invalid.');
        }

        return new self($id, $code, $nameAr, $nameEn);
    }

    /** @return array{id: string, code: string, name_ar: string, name_en: ?string, status: string, lock_version: int} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name_ar' => $this->nameAr,
            'name_en' => $this->nameEn,
            'status' => 'active',
            'lock_version' => 1,
        ];
    }

    private static function assertUuidV7(string $id): void
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $id) !== 1) {
            throw new InvalidArgumentException('Cluster id must be a lowercase UUIDv7.');
        }
    }
}
