<?php

namespace Modules\Organization\Features\TemporaryAssignment\Events;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class TemporaryAssignmentEventFactory implements BuildTemporaryAssignmentEvent
{
    public function make(
        string $type,
        array $temporaryAssignment,
        string $subjectId,
        string $tenantId,
        string $correlationId,
    ): array {
        foreach ([$subjectId, $tenantId, $correlationId] as $contextId) {
            if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $contextId) !== 1) {
                throw new InvalidArgumentException('temporary_assignment_event_context_invalid');
            }
        }

        return [
            'specversion' => '1.0',
            'id' => Str::uuid7()->toString(),
            'source' => '/organization/temporary-assignments',
            'type' => $type,
            'subject' => '/temporary-assignments/'.$temporaryAssignment['id'],
            'time' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            'datacontenttype' => 'application/json',
            'correlationid' => $correlationId,
            'data' => [
                'temporary_assignment' => [
                    'id' => $temporaryAssignment['id'],
                    'person_id' => $temporaryAssignment['person_id'],
                    'organization_unit_id' => $temporaryAssignment['organization_unit_id'],
                    'capability_codes' => $temporaryAssignment['capability_codes'],
                    'start_at' => $temporaryAssignment['start_at'],
                    'end_at' => $temporaryAssignment['end_at'],
                    'state' => $temporaryAssignment['state'],
                    'lock_version' => $temporaryAssignment['lock_version'],
                ],
                'access_context' => [
                    'subject_id' => $subjectId,
                    'tenant_id' => $tenantId,
                    'organization_unit_ids' => [$temporaryAssignment['organization_unit_id']],
                    'roles' => [],
                    'clearance' => 'internal',
                    'break_glass' => false,
                    'correlation_id' => $correlationId,
                ],
                'classification' => 'internal',
            ],
        ];
    }
}
