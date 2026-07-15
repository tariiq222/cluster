<?php

namespace Modules\Authorization\Tests;

use Modules\Authorization\Contracts\RecordFacts;
use Modules\Authorization\Infrastructure\FixtureFacilityDecision;
use PHPUnit\Framework\TestCase;

class FixtureFacilityDecisionTest extends TestCase
{
    private const FACILITY_A = '018f6f7d-0c00-7000-8000-000000000011';

    private const FACILITY_B = '018f6f7d-0c00-7000-8000-000000000012';

    public function test_matching_facility_allows_fixture_submit_read_and_list_capabilities(): void
    {
        $decision = new FixtureFacilityDecision();
        $facts = new RecordFacts(self::FACILITY_A, 'work_record', 'internal');

        foreach (['work_record.submit', 'work_record.read', 'work_record.list'] as $capability) {
            $result = $decision->decide(['facility_id' => self::FACILITY_A], $capability, $facts);

            $this->assertSame('allow', $result['decision']);
            $this->assertSame(['facility_scope_match'], $result['reason_codes']);
        }
    }

    public function test_mismatched_or_unavailable_facts_fail_closed_before_serialization(): void
    {
        $decision = new FixtureFacilityDecision();

        $mismatched = $decision->decide(
            ['facility_id' => self::FACILITY_A],
            'work_record.read',
            new RecordFacts(self::FACILITY_B, 'work_record', 'internal'),
        );
        $missingFacts = $decision->decide(['facility_id' => self::FACILITY_A], 'work_record.read', null);
        $missingOwner = $decision->decide(
            ['facility_id' => self::FACILITY_A],
            'work_record.read',
            new RecordFacts(null, 'work_record', 'internal'),
        );

        $this->assertSame('deny', $mismatched['decision']);
        $this->assertSame(['facility_scope_mismatch'], $mismatched['reason_codes']);
        $this->assertSame('deny', $missingFacts['decision']);
        $this->assertSame(['record_facts_unavailable'], $missingFacts['reason_codes']);
        $this->assertSame('deny', $missingOwner['decision']);
        $this->assertSame(['owner_facility_missing'], $missingOwner['reason_codes']);
    }
}
