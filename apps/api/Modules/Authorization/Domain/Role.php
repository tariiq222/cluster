<?php

namespace Modules\Authorization\Domain;

use InvalidArgumentException;

final readonly class Role
{
    private function __construct(
        public string $id,
        public string $code,
        public string $nameAr,
        public ?string $nameEn,
        public string $roleType,
        public bool $isSystemRole,
    ) {}

    public static function define(
        string $id,
        string $code,
        string $nameAr,
        ?string $nameEn,
        string $roleType,
        bool $isSystemRole = false,
    ): self {
        UuidV7::assert($id, 'Role id');
        if (! self::isCode($code, 96)
            || ! self::isCode($roleType, 32)
            || trim($nameAr) === ''
            || mb_strlen($nameAr) > 255
            || ($nameEn !== null && (trim($nameEn) === '' || mb_strlen($nameEn) > 255))) {
            throw new InvalidArgumentException('Role data is invalid.');
        }

        return new self($id, $code, $nameAr, $nameEn, $roleType, $isSystemRole);
    }

    /** @return array{id: string, code: string, name_ar: string, name_en: ?string, role_type: string, status: string, is_system_role: bool} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name_ar' => $this->nameAr,
            'name_en' => $this->nameEn,
            'role_type' => $this->roleType,
            'status' => 'active',
            'is_system_role' => $this->isSystemRole,
        ];
    }

    private static function isCode(string $value, int $maximumLength): bool
    {
        return mb_strlen($value) <= $maximumLength
            && preg_match('/\A[a-z][a-z0-9_-]*\z/', $value) === 1;
    }
}
