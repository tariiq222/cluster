<?php

namespace Modules\Organization\Domain;

use InvalidArgumentException;

final readonly class Position
{
    private function __construct(
        public string $id,
        public string $organizationUnitId,
        public string $code,
        public string $titleAr,
        public ?string $managerPositionId,
    ) {}

    public static function create(
        string $id,
        string $organizationUnitId,
        string $code,
        string $titleAr,
        ?string $managerPositionId,
    ): self {
        foreach (array_filter([$id, $organizationUnitId, $managerPositionId]) as $uuid) {
            if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) !== 1) {
                throw new InvalidArgumentException('Position identifiers must be lowercase UUIDv7 values.');
            }
        }
        if (preg_match('/\A[A-Z0-9_-]{2,64}\z/', $code) !== 1 || $titleAr === '' || mb_strlen($titleAr) > 255) {
            throw new InvalidArgumentException('Position data is invalid.');
        }

        return new self($id, $organizationUnitId, $code, $titleAr, $managerPositionId);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'organization_unit_id' => $this->organizationUnitId,
            'code' => $this->code,
            'title_ar' => $this->titleAr,
            'manager_position_id' => $this->managerPositionId,
            'is_active' => true,
            'lock_version' => 1,
        ];
    }
}
