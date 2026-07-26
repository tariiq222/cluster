<?php

namespace Modules\Authorization\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Modules\Authorization\Contracts\AccessProjection;
use Modules\Authorization\Domain\ClassificationLevel;
use Modules\Authorization\Domain\ClassificationPolicy;
use Modules\Authorization\Domain\FieldAccessTemplate;
use Modules\Authorization\Domain\FieldDecision;
use Modules\Authorization\Domain\SensitiveAccessEvent;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ClassificationFieldAuditDomainTest extends TestCase
{
    public function test_classification_levels_compare_in_canonical_order(): void
    {
        $this->assertSame([
            ClassificationLevel::PUBLIC,
            ClassificationLevel::INTERNAL,
            ClassificationLevel::CONFIDENTIAL,
            ClassificationLevel::TOP_SECRET,
        ], ClassificationLevel::ordered());
        $this->assertSame(-1, ClassificationLevel::PUBLIC->compare(ClassificationLevel::INTERNAL));
        $this->assertSame(0, ClassificationLevel::CONFIDENTIAL->compare(ClassificationLevel::CONFIDENTIAL));
        $this->assertSame(1, ClassificationLevel::TOP_SECRET->compare(ClassificationLevel::CONFIDENTIAL));
        $this->assertTrue(ClassificationLevel::TOP_SECRET->isAtLeast(ClassificationLevel::CONFIDENTIAL));
        $this->assertFalse(ClassificationLevel::INTERNAL->isAtLeast(ClassificationLevel::CONFIDENTIAL));
    }

    public function test_classification_policy_uses_clearance_order_without_permitting_lower_clearance(): void
    {
        $policy = new ClassificationPolicy(
            classification: ClassificationLevel::CONFIDENTIAL,
            minimumCapability: 'work_record.read.confidential',
            exportPolicy: 'restricted',
            downloadPolicy: 'restricted',
            policyVersion: 'v1',
        );

        $this->assertFalse($policy->permits(ClassificationLevel::INTERNAL));
        $this->assertTrue($policy->permits(ClassificationLevel::CONFIDENTIAL));
        $this->assertTrue($policy->permits(ClassificationLevel::TOP_SECRET));
    }

    public function test_field_access_template_validates_field_paths_and_hides_unspecified_fields(): void
    {
        $template = new FieldAccessTemplate(
            fieldPolicyKey: 'work_record.default',
            moduleCode: 'work_records',
            fieldDecisions: [
                'payload.public_summary' => FieldDecision::READ,
                'payload.budget_amount' => FieldDecision::HIDE,
                'payload.status' => FieldDecision::EDIT,
            ],
            policyVersion: 'v1',
        );

        $this->assertSame(FieldDecision::READ, $template->decisionFor('payload.public_summary'));
        $this->assertSame(FieldDecision::HIDE, $template->decisionFor('payload.unlisted'));

        $this->expectException(InvalidArgumentException::class);
        new FieldAccessTemplate(
            fieldPolicyKey: 'work_record.invalid',
            moduleCode: 'work_records',
            fieldDecisions: ['' => FieldDecision::HIDE],
            policyVersion: 'v1',
        );
    }

    public function test_field_access_template_normalizes_unqualified_paths_to_payload_paths(): void
    {
        $template = new FieldAccessTemplate(
            fieldPolicyKey: 'work_record.normalized',
            moduleCode: 'work_records',
            fieldDecisions: [
                'summary' => FieldDecision::READ,
                'payload.status' => FieldDecision::EDIT,
            ],
            policyVersion: 'v1',
        );

        $this->assertSame(FieldDecision::READ, $template->decisionFor('payload.summary'));
        $this->assertSame(FieldDecision::EDIT, $template->decisionFor('payload.status'));
        $this->assertSame(FieldDecision::HIDE, $template->decisionFor('summary'));
    }

    public function test_access_projection_maps_payload_paths_to_serializer_fields(): void
    {
        $projection = new AccessProjection(
            decisionId: '0197f0e0-0000-7000-8000-000000000407',
            allowedActions: ['read'],
            fieldAccess: [
                '*' => 'hidden',
                'payload.summary' => 'readonly',
                'payload.budget_amount' => 'masked',
                'payload.internal_memo' => 'hidden',
            ],
        );

        $result = $projection->compose([
            'payload' => [
                'summary' => 'visible',
                'budget_amount' => 50000,
                'internal_memo' => 'secret',
                'unmapped' => 'hidden by wildcard',
            ],
        ], static function (array $payload, array $fieldAccess): array {
            $wildcard = $fieldAccess['*'] ?? null;
            foreach ($payload as $field => $value) {
                $state = $fieldAccess[$field] ?? $wildcard;
                if ($state === 'hidden') {
                    unset($payload[$field]);
                } elseif ($state === 'masked') {
                    $payload[$field] = '***';
                }
            }

            return $payload;
        });

        $this->assertSame([
            'summary' => 'visible',
            'budget_amount' => '***',
        ], $result['payload']);
        $this->assertSame([
            '*' => 'hidden',
            'payload.summary' => 'readonly',
            'payload.budget_amount' => 'masked',
            'payload.internal_memo' => 'hidden',
        ], (array) $result['field_access']);
    }

    public function test_sensitive_access_event_is_immutable_and_requires_sensitive_classification(): void
    {
        $event = new SensitiveAccessEvent(
            eventId: '0197f0e0-0000-7000-8000-000000000401',
            accessDecisionId: '0197f0e0-0000-7000-8000-000000000402',
            actorUserId: '0197f0e0-0000-7000-8000-000000000403',
            originalActorUserId: '0197f0e0-0000-7000-8000-000000000404',
            resourceType: 'work_record',
            resourceId: '0197f0e0-0000-7000-8000-000000000405',
            action: 'read',
            classification: ClassificationLevel::CONFIDENTIAL,
            correlationId: '0197f0e0-0000-7000-8000-000000000406',
            sourceIp: '192.0.2.1',
            deviceFingerprintHash: hash('sha256', 'test-device'),
            occurredAt: new DateTimeImmutable('2026-07-19T10:00:00Z'),
        );

        $this->assertSame('read', $event->action);
        $this->assertTrue((new ReflectionClass($event))->isReadOnly());
    }

    public function test_sensitive_access_event_rejects_non_sensitive_classification(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SensitiveAccessEvent(
            eventId: '0197f0e0-0000-7000-8000-000000000401',
            accessDecisionId: '0197f0e0-0000-7000-8000-000000000402',
            actorUserId: '0197f0e0-0000-7000-8000-000000000403',
            originalActorUserId: '0197f0e0-0000-7000-8000-000000000404',
            resourceType: 'work_record',
            resourceId: '0197f0e0-0000-7000-8000-000000000405',
            action: 'read',
            classification: ClassificationLevel::INTERNAL,
            correlationId: '0197f0e0-0000-7000-8000-000000000406',
            sourceIp: null,
            deviceFingerprintHash: null,
            occurredAt: new DateTimeImmutable('2026-07-19T10:00:00Z'),
        );
    }
}
