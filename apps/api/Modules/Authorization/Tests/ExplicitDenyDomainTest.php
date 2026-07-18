<?php

namespace Modules\Authorization\Tests;

use InvalidArgumentException;
use Modules\Authorization\Domain\ClassificationLevel;
use Modules\Authorization\Domain\ExplicitDeny;
use PHPUnit\Framework\TestCase;

class ExplicitDenyDomainTest extends TestCase
{
    private const DENY_ID = '018f6f7d-0c00-7000-8000-000000000961';

    private const USER_ID = '018f6f7d-0c00-7000-8000-000000000962';

    private const ISSUER_ID = '018f6f7d-0c00-7000-8000-000000000963';

    private const ORGANIZATION_UNIT_ID = '018f6f7d-0c00-7000-8000-000000000964';

    public function test_explicit_deny_requires_a_valid_target_capability_and_bounded_window(): void
    {
        $deny = ExplicitDeny::create(
            self::DENY_ID,
            self::USER_ID,
            'work_record.read',
            ClassificationLevel::CONFIDENTIAL,
            self::ORGANIZATION_UNIT_ID,
            'work_*',
            'Restricted investigation access.',
            self::ISSUER_ID,
            '2026-07-19T12:00:00.000Z',
            '2026-07-20T12:00:00.000Z',
            true,
        );

        $this->assertSame([
            'id' => self::DENY_ID,
            'user_id' => self::USER_ID,
            'capability_code' => 'work_record.read',
            'classification' => 'confidential',
            'organization_unit_id' => self::ORGANIZATION_UNIT_ID,
            'resource_pattern' => 'work_*',
            'reason' => 'Restricted investigation access.',
            'issued_by_user_id' => self::ISSUER_ID,
            'issued_at' => '2026-07-19T12:00:00.000Z',
            'expires_at' => '2026-07-20T12:00:00.000Z',
            'revocable' => true,
        ], $deny->toArray());
        $this->assertTrue(ExplicitDeny::matchesResourceType('work_*', 'work_record'));
        $this->assertFalse(ExplicitDeny::matchesResourceType('work_*', 'document'));

        $this->assertInvalid(fn (): ExplicitDeny => ExplicitDeny::create(
            self::DENY_ID,
            null,
            'work_record.read',
            null,
            null,
            null,
            'No target.',
            self::ISSUER_ID,
            '2026-07-19T12:00:00.000Z',
            null,
            true,
        ));
        $this->assertInvalid(fn (): ExplicitDeny => ExplicitDeny::create(
            self::DENY_ID,
            self::USER_ID,
            'work_record.read',
            null,
            null,
            null,
            'Invalid window.',
            self::ISSUER_ID,
            '2026-07-19T12:00:00.000Z',
            '2026-07-19T12:00:00.000Z',
            true,
        ));
    }

    private function assertInvalid(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected explicit deny validation to fail.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }
}
