<?php

namespace Modules\Organization\Contracts;

interface ListActiveTemporaryAssignmentFacts
{
    /**
     * Returns only facts whose half-open validity window contains the current
     * UTC clock instant and which have not been revoked.
     *
     * @return list<array{
     *     temporary_assignment_id: string,
     *     person_id: string,
     *     organization_unit_id: string,
     *     capability_codes: list<string>,
     *     valid_from: string,
     *     valid_until: string
     * }>
     */
    public function forPerson(string $personId): array;
}
