<?php

namespace Modules\Organization\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Modules\Organization\Contracts\ValidatePersonReference;
use stdClass;

final class ValidatePersonReferenceFromPersistence implements ValidatePersonReference
{
    public function find(string $personId): ?array
    {
        $person = DB::table('people')->where('id', $personId)->first([
            'id',
            'person_version',
            'status',
            'display_name_ar',
            'display_name_en',
        ]);

        return $person instanceof stdClass ? $this->serialize($person) : null;
    }

    public function validate(string $personId, int $personVersion): array
    {
        $person = DB::table('people')->where('id', $personId)->lockForUpdate()->first([
            'id',
            'person_version',
            'status',
            'display_name_ar',
            'display_name_en',
        ]);
        if (! $person instanceof stdClass) {
            return ['state' => 'missing', 'reference' => null];
        }
        $reference = $this->serialize($person);

        return [
            'state' => $reference['person_version'] === $personVersion ? 'current' : 'stale',
            'reference' => $reference,
        ];
    }

    /** @return array{person_id: string, person_version: int, status: string, display_name_ar: string, display_name_en: string|null} */
    private function serialize(stdClass $person): array
    {
        return [
            'person_id' => (string) $person->id,
            'person_version' => (int) $person->person_version,
            'status' => (string) $person->status,
            'display_name_ar' => (string) $person->display_name_ar,
            'display_name_en' => is_string($person->display_name_en) ? $person->display_name_en : null,
        ];
    }
}
