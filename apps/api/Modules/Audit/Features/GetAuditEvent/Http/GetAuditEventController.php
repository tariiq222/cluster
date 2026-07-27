<?php

declare(strict_types=1);

namespace Modules\Audit\Features\GetAuditEvent\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Audit\Contracts\AuditActivityQuery;
use Modules\Audit\Contracts\AuditEventInput;
use Modules\Audit\Features\GetAuditEvent\Handler\GetAuditEventHandler;
use Modules\Audit\Http\AuditApi;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Identity\Contracts\ResolvePrincipalContext;

final class GetAuditEventController
{
    public function __construct(
        private readonly ResolvePrincipalContext $principals,
        private readonly DecideAccess $access,
        private readonly GetAuditEventHandler $handler,
    ) {}

    public function __invoke(Request $request, string $eventId): JsonResponse
    {
        $correlationId = AuditApi::correlationId($request);
        $principal = $this->principals->resolve($request);
        if ($principal === null) {
            return AuditApi::problem(
                401,
                'authentication-required',
                'Unauthorized',
                'Authentication is required.',
                $correlationId,
            );
        }

        $scope = AuditApi::scope($principal);
        $baseDecision = AuditApi::collectionDecision(
            $principal,
            $scope,
            $correlationId,
            $this->access,
        );
        if (! $baseDecision->isAllowed()) {
            return AuditApi::notFound($correlationId);
        }
        if ($correlationId === null) {
            return AuditApi::problem(
                400,
                'invalid-correlation-id',
                'Bad Request',
                'X-Correlation-ID must be a lowercase UUIDv7.',
            );
        }
        if ($request->query() !== []) {
            return AuditApi::problem(
                400,
                'invalid-query-parameter',
                'Bad Request',
                'The query contains an unsupported parameter.',
                $correlationId,
            );
        }

        try {
            AuditEventInput::assertUuidV7($eventId, 'eventId');
        } catch (InvalidArgumentException) {
            return AuditApi::notFound($correlationId);
        }

        $query = new AuditActivityQuery(
            principalId: $principal->userId,
            facilityId: $scope['facility_id'],
            organizationUnitIds: $scope['organization_unit_ids'],
            cursor: null,
            sourceModule: null,
            action: null,
            actorId: null,
            subjectType: null,
            subjectId: null,
            correlationId: null,
            classification: null,
            occurredFrom: null,
            occurredTo: null,
            limit: 1,
        );
        $item = $this->handler->handle($query, $eventId);
        if ($item === null) {
            return AuditApi::notFound($correlationId);
        }

        return AuditApi::response(['data' => AuditApi::serialize($item)], $correlationId);
    }
}
