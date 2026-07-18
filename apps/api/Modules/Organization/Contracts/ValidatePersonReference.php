<?php

namespace Modules\Organization\Contracts;

interface ValidatePersonReference
{
    /** @return array{person_id: string, person_version: int, status: string, display_name_ar: string, display_name_en: string|null}|null */
    public function find(string $personId): ?array;

    /**
     * @return array{
     *   state: 'current'|'missing'|'stale',
     *   reference: array{person_id: string, person_version: int, status: string, display_name_ar: string, display_name_en: string|null}|null
     * }
     */
    public function validate(string $personId, int $personVersion): array;
}
