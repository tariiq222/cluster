<?php

namespace Modules\WorkRecords\Domain\Tests;

use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Modules\WorkRecords\Domain\WorkRecord;
use Tests\TestCase;

class WorkRecordEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    private const RECORD_ID = '0197f0e0-0000-7000-8000-000000000101';

    private const WORK_TYPE_VERSION_ID = '0197f0e0-0000-7000-8000-000000000001';

    private const FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000011';

    private const CREATOR_ID = '018f6f7d-0c00-7000-8000-000000000021';

    public function test_submitted_record_requires_the_canonical_envelope_invariants(): void
    {
        $submittedAt = new DateTimeImmutable('2026-07-16T10:00:00Z');

        $record = WorkRecord::submit(
            id: self::RECORD_ID,
            recordNumber: 'WR-000001',
            workTypeVersionId: self::WORK_TYPE_VERSION_ID,
            ownerFacilityId: self::FACILITY_ID,
            creatorUserId: self::CREATOR_ID,
            classification: 'internal',
            payload: ['title' => 'طلب اختبار', 'description' => 'وصف الاختبار'],
            submittedAt: $submittedAt,
        );

        $this->assertSame([
            'id' => self::RECORD_ID,
            'record_number' => 'WR-000001',
            'work_type_version_id' => self::WORK_TYPE_VERSION_ID,
            'owner' => ['facility_id' => self::FACILITY_ID, 'user_id' => self::CREATOR_ID],
            'status' => 'submitted',
            'classification' => 'internal',
            'payload' => ['title' => 'طلب اختبار', 'description' => 'وصف الاختبار'],
            'lock_version' => 1,
            'submitted_at' => '2026-07-16T10:00:00.000Z',
            'created_at' => '2026-07-16T10:00:00.000Z',
            'updated_at' => '2026-07-16T10:00:00.000Z',
        ], $record->toEnvelope());
    }

    public function test_missing_owner_or_invalid_classification_is_rejected_before_persistence(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WorkRecord::submit(
            id: self::RECORD_ID,
            recordNumber: 'WR-000001',
            workTypeVersionId: self::WORK_TYPE_VERSION_ID,
            ownerFacilityId: '',
            creatorUserId: self::CREATOR_ID,
            classification: 'external',
            payload: ['title' => 'طلب اختبار', 'description' => 'وصف الاختبار'],
            submittedAt: new DateTimeImmutable('2026-07-16T10:00:00Z'),
        );
    }

    public function test_work_records_owns_the_envelope_and_outbox_tables_without_cross_module_foreign_keys(): void
    {
        $this->assertTrue(Schema::hasColumns('work_records', [
            'id',
            'record_number',
            'work_type_version_id',
            'owner_facility_id',
            'creator_user_id',
            'status',
            'classification',
            'payload',
            'lock_version',
            'submitted_at',
        ]));
        $this->assertTrue(Schema::hasColumns('outbox_events', [
            'event_id',
            'aggregate_id',
            'event_type',
            'cloud_event',
            'occurred_at',
            'published_at',
        ]));
    }
}
