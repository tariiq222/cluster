<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Outbox;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Shared\Contracts\OutboxConflictException;
use Shared\Contracts\OutboxDuplicatePolicy;
use Shared\Infrastructure\Outbox\DatabaseTransactionalOutbox;
use Tests\TestCase;

final class DatabaseTransactionalOutboxTest extends TestCase
{
    use RefreshDatabase;

    private const EVENT_ID = '0197f0e0-0000-7000-8000-000000000501';

    private const AGGREGATE_ID = '0197f0e0-0000-7000-8000-000000000502';

    private const EVENT_TYPE = 'com.cluster.workrecord.submitted.v1';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_replayable_duplicate_with_same_content_is_a_no_op_even_when_time_changes(): void
    {
        $outbox = $this->app->make(DatabaseTransactionalOutbox::class);
        Carbon::setTestNow('2026-07-26T10:00:00Z');
        $outbox->appendWithPolicy(
            self::EVENT_ID,
            self::AGGREGATE_ID,
            self::EVENT_TYPE,
            ['record_id' => self::AGGREGATE_ID, 'status' => 'submitted'],
            OutboxDuplicatePolicy::Replayable,
        );

        Carbon::setTestNow('2026-07-26T10:05:00Z');
        $outbox->appendWithPolicy(
            self::EVENT_ID,
            self::AGGREGATE_ID,
            self::EVENT_TYPE,
            ['status' => 'submitted', 'record_id' => self::AGGREGATE_ID],
            OutboxDuplicatePolicy::Replayable,
        );

        $this->assertSame(1, DB::table('outbox_events')->count());
    }

    public function test_replayable_duplicate_with_different_content_raises_domain_conflict(): void
    {
        $outbox = $this->app->make(DatabaseTransactionalOutbox::class);
        $outbox->appendWithPolicy(
            self::EVENT_ID,
            self::AGGREGATE_ID,
            self::EVENT_TYPE,
            ['status' => 'submitted'],
            OutboxDuplicatePolicy::Replayable,
        );

        $this->expectException(OutboxConflictException::class);
        $outbox->appendWithPolicy(
            self::EVENT_ID,
            self::AGGREGATE_ID,
            self::EVENT_TYPE,
            ['status' => 'archived'],
            OutboxDuplicatePolicy::Replayable,
        );
    }

    public function test_strict_duplicate_preserves_the_database_unique_constraint_failure(): void
    {
        $outbox = $this->app->make(DatabaseTransactionalOutbox::class);
        $payload = ['status' => 'submitted'];
        $outbox->appendWithPolicy(
            self::EVENT_ID,
            self::AGGREGATE_ID,
            self::EVENT_TYPE,
            $payload,
        );

        try {
            $outbox->appendWithPolicy(
                self::EVENT_ID,
                self::AGGREGATE_ID,
                self::EVENT_TYPE,
                $payload,
            );
            $this->fail('Strict duplicate writes must preserve the unique-constraint failure.');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(1, DB::table('outbox_events')->count());
        }
    }
}
