<?php

namespace Modules\WorkRecords\Features\Lifecycle\Handler\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Organization\Contracts\ResolveOrganizationScopeAncestry;
use Modules\WorkRecords\Features\Lifecycle\Handler\WorkRecordLifecycleMutator;
use PHPUnit\Framework\Attributes\DataProvider;
use Shared\Contracts\TransactionalOutbox;
use Tests\TestCase;

final class RecordingTransactionalOutbox implements TransactionalOutbox
{
    public array $events = [];

    public function append(string $eventId, string $aggregateId, string $eventType, array $payload): void
    {
        $this->events[] = compact('eventId', 'aggregateId', 'eventType', 'payload');
    }
}

final class WorkRecordLifecycleMutatorTest extends TestCase
{
    use RefreshDatabase;

    private const RECORD_ID = '019a0000-0000-7000-8000-000000000101';

    private const FACILITY_ID = '019a0000-0000-7000-8000-000000000102';

    private const USER_ID = '019a0000-0000-7000-8000-000000000103';

    private const SUBMITTED_AT = '2026-07-01 10:20:30';

    #[DataProvider('invalidTransitionProvider')]
    public function test_invalid_transitions_are_rejected_without_mutating_the_record(string $status, string $action): void
    {
        $this->seedRecord($status);
        $outbox = $this->recordingOutbox();

        $result = $this->mutator($outbox)->transition(
            self::RECORD_ID,
            $action,
            $this->principal(),
            '019a0000-0000-7000-8000-000000000104',
            1,
        );

        $this->assertFalse($result['ok']);
        $this->assertSame(409, $result['problem']['status']);
        $this->assertSame('invalid-record-transition', $result['problem']['type']);
        $this->assertDatabaseHas('work_records', [
            'id' => self::RECORD_ID,
            'status' => $status,
            'lock_version' => 1,
            'submitted_at' => self::SUBMITTED_AT,
        ]);
        $this->assertSame([], $outbox->events);
    }

    public static function invalidTransitionProvider(): array
    {
        return [
            'completed records cannot be returned' => ['completed', 'return'],
            'cancelled records cannot be submitted' => ['cancelled', 'submit'],
            'submitted records cannot be submitted again' => ['submitted', 'submit'],
        ];
    }

    #[DataProvider('validTransitionProvider')]
    public function test_valid_transitions_preserve_the_original_submitted_at(string $status, string $action, string $expectedStatus): void
    {
        $this->seedRecord($status);
        $outbox = $this->recordingOutbox();

        $result = $this->mutator($outbox)->transition(
            self::RECORD_ID,
            $action,
            $this->principal(),
            '019a0000-0000-7000-8000-000000000105',
            1,
        );

        $this->assertTrue($result['ok']);
        $this->assertDatabaseHas('work_records', [
            'id' => self::RECORD_ID,
            'status' => $expectedStatus,
            'lock_version' => 2,
            'submitted_at' => self::SUBMITTED_AT,
        ]);
        $this->assertCount(1, $outbox->events);
    }

    public static function validTransitionProvider(): array
    {
        return [
            'submitted records can be returned' => ['submitted', 'return', 'returned'],
            'returned records can be resubmitted' => ['returned', 'submit', 'submitted'],
            'submitted records can be completed' => ['submitted', 'complete', 'completed'],
            'submitted records can complete submission via the legacy alias of complete' => ['submitted', 'complete-submission', 'completed'],
            'submitted records can be cancelled' => ['submitted', 'cancel', 'cancelled'],
            'returned records can be cancelled' => ['returned', 'cancel', 'cancelled'],
            'completed records can be archived' => ['completed', 'archive', 'archived'],
            'cancelled records can be archived' => ['cancelled', 'archive', 'archived'],
        ];
    }

    private function seedRecord(string $status): void
    {
        DB::table('work_records')->insert([
            'id' => self::RECORD_ID,
            'record_number' => 'WR-LIFECYCLE-001',
            'work_type_version_id' => '019a0000-0000-7000-8000-000000000106',
            'owner_facility_id' => self::FACILITY_ID,
            'creator_user_id' => self::USER_ID,
            'classification' => 'internal',
            'field_policy_key' => 'request',
            'status' => $status,
            'payload' => json_encode(['title' => 'Lifecycle regression'], JSON_THROW_ON_ERROR),
            'lock_version' => 1,
            'submitted_at' => self::SUBMITTED_AT,
            'created_at' => '2026-07-01 10:00:00',
            'updated_at' => '2026-07-01 10:20:30',
        ]);
    }

    private function mutator(TransactionalOutbox $outbox): WorkRecordLifecycleMutator
    {
        $access = new class implements DecideAccess
        {
            public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
            {
                return new AccessDecision('allow', $capability, 'work_record', [], 'test', 'test', 'internal');
            }

            public function evaluateOnly(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
            {
                return $this->decide($actor, $capability, $facts);
            }
        };
        $ancestry = new class implements ResolveOrganizationScopeAncestry
        {
            public function ancestry(string $scopeType, string $scopeId): array
            {
                return ['cluster_id' => null, 'facility_id' => $scopeId, 'unit_id' => null];
            }

            public function facilityClusterIds(array $facilityIds): array
            {
                return array_fill_keys($facilityIds, null);
            }
        };

        return new WorkRecordLifecycleMutator($outbox, $access, $ancestry);
    }

    private function recordingOutbox(): RecordingTransactionalOutbox
    {
        return new RecordingTransactionalOutbox;
    }

    private function principal(): array
    {
        return ['user_id' => self::USER_ID, 'facility_id' => self::FACILITY_ID];
    }
}
