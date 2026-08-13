<?php

declare(strict_types=1);

namespace Modules\Organization\Tests\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Modules\Organization\Domain\RelationshipCapability;
use Modules\Organization\Domain\SupervisoryRelationship;
use PHPUnit\Framework\TestCase;

final class SupervisoryRelationshipTest extends TestCase
{
    private const RELATIONSHIP_ID = '018f6f7d-0c00-7000-8000-000000000060';

    private const CAPABILITY_ID = '018f6f7d-0c00-7000-8000-000000000061';

    private const SOURCE_UNIT_ID = '018f6f7d-0c00-7000-8000-000000000062';

    private const TARGET_UNIT_ID = '018f6f7d-0c00-7000-8000-000000000063';

    public function test_create_returns_value_object_with_supplied_fields(): void
    {
        $from = new DateTimeImmutable('2026-07-18T10:00:00Z');
        $until = new DateTimeImmutable('2026-07-19T10:00:00Z');

        $rel = SupervisoryRelationship::create(
            self::RELATIONSHIP_ID,
            self::SOURCE_UNIT_ID,
            self::TARGET_UNIT_ID,
            'direct',
            $from,
            $until,
        );

        self::assertSame(self::RELATIONSHIP_ID, $rel->id);
        self::assertSame(self::SOURCE_UNIT_ID, $rel->sourceOrganizationUnitId);
        self::assertSame(self::TARGET_UNIT_ID, $rel->targetOrganizationUnitId);
        self::assertSame('direct', $rel->relationshipType);
        self::assertSame($from->format("Y-m-d\TH:i:s.v\Z"), $rel->validFrom->format("Y-m-d\TH:i:s.v\Z"));
        self::assertSame($until->format("Y-m-d\TH:i:s.v\Z"), $rel->validUntil->format("Y-m-d\TH:i:s.v\Z"));
        self::assertSame([], $rel->capabilities);
    }

    public function test_create_accepts_each_allowed_relationship_type(): void
    {
        foreach (['direct', 'functional', 'coordination', 'read_only'] as $type) {
            $rel = SupervisoryRelationship::create(
                self::RELATIONSHIP_ID,
                self::SOURCE_UNIT_ID,
                self::TARGET_UNIT_ID,
                $type,
                new DateTimeImmutable('2026-07-18T10:00:00Z'),
                new DateTimeImmutable('2026-07-19T10:00:00Z'),
            );

            self::assertSame($type, $rel->relationshipType);
        }
    }

    public function test_create_normalizes_non_utc_valid_from_to_utc(): void
    {
        $rel = SupervisoryRelationship::create(
            self::RELATIONSHIP_ID,
            self::SOURCE_UNIT_ID,
            self::TARGET_UNIT_ID,
            'direct',
            new DateTimeImmutable('2026-07-18T10:00:00+03:00'),
            new DateTimeImmutable('2026-07-19T10:00:00+03:00'),
        );

        self::assertSame('UTC', $rel->validFrom->getTimezone()->getName());
        self::assertSame('2026-07-18T07:00:00.000Z', $rel->validFrom->format('Y-m-d\TH:i:s.v\Z'));
    }

    public function test_create_normalizes_non_utc_valid_until_to_utc(): void
    {
        $rel = SupervisoryRelationship::create(
            self::RELATIONSHIP_ID,
            self::SOURCE_UNIT_ID,
            self::TARGET_UNIT_ID,
            'direct',
            new DateTimeImmutable('2026-07-18T10:00:00+03:00'),
            new DateTimeImmutable('2026-07-19T10:00:00+03:00'),
        );

        self::assertSame('UTC', $rel->validUntil->getTimezone()->getName());
        self::assertSame('2026-07-19T07:00:00.000Z', $rel->validUntil->format('Y-m-d\TH:i:s.v\Z'));
    }

    public function test_create_preserves_already_utc_inputs(): void
    {
        $rel = SupervisoryRelationship::create(
            self::RELATIONSHIP_ID,
            self::SOURCE_UNIT_ID,
            self::TARGET_UNIT_ID,
            'direct',
            new DateTimeImmutable('2026-07-18T10:00:00Z'),
            new DateTimeImmutable('2026-07-19T10:00:00Z'),
        );

        self::assertSame('UTC', $rel->validFrom->getTimezone()->getName());
        self::assertSame('2026-07-18T10:00:00.000Z', $rel->validFrom->format('Y-m-d\TH:i:s.v\Z'));
        self::assertSame('2026-07-19T10:00:00.000Z', $rel->validUntil->format('Y-m-d\TH:i:s.v\Z'));
    }

    public function test_to_persistence_emits_database_timestamp_format(): void
    {
        $rel = SupervisoryRelationship::create(
            self::RELATIONSHIP_ID,
            self::SOURCE_UNIT_ID,
            self::TARGET_UNIT_ID,
            'direct',
            new DateTimeImmutable('2026-07-18T10:00:00Z'),
            new DateTimeImmutable('2026-07-19T10:00:00Z'),
        );

        self::assertSame([
            'id' => self::RELATIONSHIP_ID,
            'source_organization_unit_id' => self::SOURCE_UNIT_ID,
            'target_organization_unit_id' => self::TARGET_UNIT_ID,
            'relationship_type' => 'direct',
            'valid_from' => '2026-07-18 10:00:00.000',
            'valid_until' => '2026-07-19 10:00:00.000',
        ], $rel->toPersistence());
    }

    public function test_to_fact_includes_capabilities_with_relationship_id(): void
    {
        $capability = RelationshipCapability::create(
            self::CAPABILITY_ID,
            self::RELATIONSHIP_ID,
            'tasks',
            'view_details',
        );

        $rel = SupervisoryRelationship::create(
            self::RELATIONSHIP_ID,
            self::SOURCE_UNIT_ID,
            self::TARGET_UNIT_ID,
            'direct',
            new DateTimeImmutable('2026-07-18T10:00:00Z'),
            new DateTimeImmutable('2026-07-19T10:00:00Z'),
            [$capability],
        );

        $fact = $rel->toFact();

        self::assertSame(self::RELATIONSHIP_ID, $fact['supervisory_relationship_id']);
        self::assertSame(self::SOURCE_UNIT_ID, $fact['source_organization_unit_id']);
        self::assertSame(self::TARGET_UNIT_ID, $fact['target_organization_unit_id']);
        self::assertSame('direct', $fact['relationship_type']);
        self::assertSame('2026-07-18T10:00:00.000Z', $fact['valid_from']);
        self::assertSame('2026-07-19T10:00:00.000Z', $fact['valid_until']);
        self::assertSame([[
            'relationship_capability_id' => self::CAPABILITY_ID,
            'module_code' => 'tasks',
            'capability_code' => 'view_details',
        ]], $fact['relationship_capabilities']);
    }

    public function test_is_active_at_returns_true_for_inclusive_start_and_exclusive_end(): void
    {
        $rel = SupervisoryRelationship::create(
            self::RELATIONSHIP_ID,
            self::SOURCE_UNIT_ID,
            self::TARGET_UNIT_ID,
            'direct',
            new DateTimeImmutable('2026-07-18T10:00:00Z'),
            new DateTimeImmutable('2026-07-19T10:00:00Z'),
        );

        self::assertTrue($rel->isActiveAt(new DateTimeImmutable('2026-07-18T10:00:00.000Z')));
        self::assertTrue($rel->isActiveAt(new DateTimeImmutable('2026-07-18T10:00:00.500Z')));
        self::assertTrue($rel->isActiveAt(new DateTimeImmutable('2026-07-19T09:59:59.999Z')));
        self::assertFalse($rel->isActiveAt(new DateTimeImmutable('2026-07-19T10:00:00.000Z')));
        self::assertFalse($rel->isActiveAt(new DateTimeImmutable('2026-07-18T09:59:59.999Z')));
    }

    public function test_is_active_at_normalizes_argument_to_utc_before_comparison(): void
    {
        $rel = SupervisoryRelationship::create(
            self::RELATIONSHIP_ID,
            self::SOURCE_UNIT_ID,
            self::TARGET_UNIT_ID,
            'direct',
            new DateTimeImmutable('2026-07-18T10:00:00Z'),
            new DateTimeImmutable('2026-07-19T10:00:00Z'),
        );

        // 2026-07-18T13:00:00+03:00 == 2026-07-18T10:00:00Z (inclusive start).
        self::assertTrue($rel->isActiveAt(new DateTimeImmutable('2026-07-18T13:00:00+03:00')));
    }

    public function test_active_fact_at_returns_null_when_inactive(): void
    {
        $rel = SupervisoryRelationship::create(
            self::RELATIONSHIP_ID,
            self::SOURCE_UNIT_ID,
            self::TARGET_UNIT_ID,
            'direct',
            new DateTimeImmutable('2026-07-18T10:00:00Z'),
            new DateTimeImmutable('2026-07-19T10:00:00Z'),
        );

        self::assertNull($rel->activeFactAt(new DateTimeImmutable('2026-07-19T10:00:00Z')));
    }

    public function test_active_fact_at_returns_fact_when_active(): void
    {
        $rel = SupervisoryRelationship::create(
            self::RELATIONSHIP_ID,
            self::SOURCE_UNIT_ID,
            self::TARGET_UNIT_ID,
            'direct',
            new DateTimeImmutable('2026-07-18T10:00:00Z'),
            new DateTimeImmutable('2026-07-19T10:00:00Z'),
        );

        $fact = $rel->activeFactAt(new DateTimeImmutable('2026-07-18T10:30:00Z'));

        self::assertNotNull($fact);
        self::assertSame(self::RELATIONSHIP_ID, $fact['supervisory_relationship_id']);
    }

    public function test_rejects_id_that_is_not_a_lowercase_uuidv7(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('supervisory_relationship_identifiers_invalid');

        SupervisoryRelationship::create(
            'not-a-uuid',
            self::SOURCE_UNIT_ID,
            self::TARGET_UNIT_ID,
            'direct',
            new DateTimeImmutable('2026-07-18T10:00:00Z'),
            new DateTimeImmutable('2026-07-19T10:00:00Z'),
        );
    }

    public function test_rejects_source_unit_id_that_is_not_a_lowercase_uuidv7(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('supervisory_relationship_identifiers_invalid');

        SupervisoryRelationship::create(
            self::RELATIONSHIP_ID,
            'not-a-uuid',
            self::TARGET_UNIT_ID,
            'direct',
            new DateTimeImmutable('2026-07-18T10:00:00Z'),
            new DateTimeImmutable('2026-07-19T10:00:00Z'),
        );
    }

    public function test_rejects_target_unit_id_that_is_not_a_lowercase_uuidv7(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('supervisory_relationship_identifiers_invalid');

        SupervisoryRelationship::create(
            self::RELATIONSHIP_ID,
            self::SOURCE_UNIT_ID,
            'not-a-uuid',
            'direct',
            new DateTimeImmutable('2026-07-18T10:00:00Z'),
            new DateTimeImmutable('2026-07-19T10:00:00Z'),
        );
    }

    public function test_rejects_unknown_relationship_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('supervisory_relationship_type_invalid');

        SupervisoryRelationship::create(
            self::RELATIONSHIP_ID,
            self::SOURCE_UNIT_ID,
            self::TARGET_UNIT_ID,
            'none',
            new DateTimeImmutable('2026-07-18T10:00:00Z'),
            new DateTimeImmutable('2026-07-19T10:00:00Z'),
        );
    }

    public function test_rejects_empty_relationship_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('supervisory_relationship_type_invalid');

        SupervisoryRelationship::create(
            self::RELATIONSHIP_ID,
            self::SOURCE_UNIT_ID,
            self::TARGET_UNIT_ID,
            '',
            new DateTimeImmutable('2026-07-18T10:00:00Z'),
            new DateTimeImmutable('2026-07-19T10:00:00Z'),
        );
    }

    public function test_rejects_period_when_valid_until_equals_valid_from(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('supervisory_relationship_period_invalid');

        $instant = new DateTimeImmutable('2026-07-18T10:00:00Z');

        SupervisoryRelationship::create(
            self::RELATIONSHIP_ID,
            self::SOURCE_UNIT_ID,
            self::TARGET_UNIT_ID,
            'direct',
            $instant,
            $instant,
        );
    }

    public function test_rejects_period_when_valid_until_before_valid_from(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('supervisory_relationship_period_invalid');

        SupervisoryRelationship::create(
            self::RELATIONSHIP_ID,
            self::SOURCE_UNIT_ID,
            self::TARGET_UNIT_ID,
            'direct',
            new DateTimeImmutable('2026-07-19T10:00:00Z'),
            new DateTimeImmutable('2026-07-18T10:00:00Z'),
        );
    }

    public function test_rejects_capability_whose_relationship_id_does_not_match(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('supervisory_relationship_capability_invalid');

        $capability = RelationshipCapability::create(
            self::CAPABILITY_ID,
            '018f6f7d-0c00-7000-8000-000000000099',
            'tasks',
            'view_details',
        );

        SupervisoryRelationship::create(
            self::RELATIONSHIP_ID,
            self::SOURCE_UNIT_ID,
            self::TARGET_UNIT_ID,
            'direct',
            new DateTimeImmutable('2026-07-18T10:00:00Z'),
            new DateTimeImmutable('2026-07-19T10:00:00Z'),
            [$capability],
        );
    }

    public function test_accepts_empty_capabilities_list_explicitly(): void
    {
        $rel = SupervisoryRelationship::create(
            self::RELATIONSHIP_ID,
            self::SOURCE_UNIT_ID,
            self::TARGET_UNIT_ID,
            'direct',
            new DateTimeImmutable('2026-07-18T10:00:00Z'),
            new DateTimeImmutable('2026-07-19T10:00:00Z'),
            [],
        );

        self::assertSame([], $rel->capabilities);
    }
}
