<?php

namespace Modules\Organization\Domain;

use InvalidArgumentException;

final readonly class Person
{
    private function __construct(
        public string $id,
        public string $employeeNumber,
        public string $displayNameAr,
        public ?string $displayNameEn,
        public string $status,
    ) {}

    public static function register(
        string $id,
        string $employeeNumber,
        string $displayNameAr,
        ?string $displayNameEn,
        string $status,
    ): self {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $id) !== 1) {
            throw new InvalidArgumentException('Person identifier must be a lowercase UUIDv7 value.');
        }
        if ($employeeNumber === '' || mb_strlen($employeeNumber) > 64
            || $displayNameAr === '' || mb_strlen($displayNameAr) > 255
            || ($displayNameEn !== null && mb_strlen($displayNameEn) > 255)
            || ! in_array($status, ['active', 'suspended', 'left'], true)) {
            throw new InvalidArgumentException('Person data is invalid.');
        }

        return new self($id, $employeeNumber, $displayNameAr, $displayNameEn, $status);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'employee_number' => $this->employeeNumber,
            'display_name_ar' => $this->displayNameAr,
            'display_name_en' => $this->displayNameEn,
            'status' => $this->status,
            'person_version' => 1,
        ];
    }
}
