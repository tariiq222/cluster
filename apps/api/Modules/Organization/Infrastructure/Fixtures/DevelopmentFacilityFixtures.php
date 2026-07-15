<?php

namespace Modules\Organization\Infrastructure\Fixtures;

final class DevelopmentFacilityFixtures
{
    public const FACILITY_A_ID = '018f6f7d-0c00-7000-8000-000000000011';

    public const FACILITY_B_ID = '018f6f7d-0c00-7000-8000-000000000012';

    /**
     * @return list<array{id: string, code: string, name: string}>
     */
    public static function facilities(): array
    {
        return [
            [
                'id' => self::FACILITY_A_ID,
                'code' => 'facility-a',
                'name' => 'Development Facility A',
            ],
            [
                'id' => self::FACILITY_B_ID,
                'code' => 'facility-b',
                'name' => 'Development Facility B',
            ],
        ];
    }
}
