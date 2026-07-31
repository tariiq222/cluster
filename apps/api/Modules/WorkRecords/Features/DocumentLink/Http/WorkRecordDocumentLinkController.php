<?php

namespace Modules\WorkRecords\Features\DocumentLink\Http;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Documents\Contracts\DocumentSourceReference;
use Modules\Documents\Contracts\LinkDocument;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\WorkRecords\Domain\WorkRecordIdempotencyConflict;
use Modules\WorkRecords\Infrastructure\Persistence\WorkRecordDocumentLinkIdempotency;

final class WorkRecordDocumentLinkController
{
    private const OPERATION = 'attachWorkRecordDocument';

    private const UNLINKABLE_STATUSES = ['archived', 'cancelled'];

    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principals,
        private readonly LinkDocument $links,
        private readonly WorkRecordDocumentLinkIdempotency $idempotency,
        private readonly DecideAccess $access,
    ) {}

    public function __invoke(Request $request, string $recordId): JsonResponse
    {
        $correlationId = $this->correlationId($request);
        if ($correlationId === null) {
            return $this->problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        $principal = $this->principals->resolve($request);
        if ($principal === null) {
            return $this->problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }
        $validator = Validator::make($request->json()->all(), [
            'document_id' => ['required', 'uuid'],
            'relation_type' => ['required', 'string', 'in:attachment,evidence'],
        ]);
        if ($validator->fails()) {
            return $this->problem(422, 'invalid-document-link', 'Unprocessable Content', 'The request body is invalid.', $correlationId);
        }
        $input = $validator->validated();

        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || preg_match('/\A[\x21-\x7E]{1,255}\z/', $key) !== 1) {
            return $this->problem(400, 'invalid-idempotency-key', 'Bad Request', 'Idempotency-Key is required.', $correlationId);
        }

        $requestHash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
        try {
            $replay = $this->idempotency->replay(
                $principal['user_id'],
                (string) $principal['facility_id'],
                self::OPERATION,
                $key,
                $requestHash,
            );
        } catch (WorkRecordIdempotencyConflict) {
            return $this->problem(409, 'idempotency-conflict', 'Conflict', 'Idempotency-Key was already used for a different request.', $correlationId);
        }
        if ($replay !== null) {
            return response()->json($replay, 201);
        }

        $record = DB::table('work_records')->where('id', $recordId)->first();
        if ($record === null) {
            return $this->problem(404, 'resource-not-found', 'Not Found', 'The work record is not available.', $correlationId);
        }
        if (in_array((string) $record->status, self::UNLINKABLE_STATUSES, true)) {
            return $this->problem(409, 'invalid-record-transition', 'Conflict', 'Documents cannot be attached to a record in its current state.', $correlationId);
        }
        $decision = $this->access->decide(
            $this->actor($principal, $correlationId),
            'work_record.read',
            new RecordFacts(
                ownerFacilityId: (string) $record->owner_facility_id,
                resourceType: 'work_record',
                classification: (string) $record->classification,
                fieldPolicyKey: isset($record->field_policy_key) && is_string($record->field_policy_key)
                    ? $record->field_policy_key
                    : null,
            ),
        );
        if (! $decision->isAllowed()) {
            return $this->problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }

        $response = ['data' => ['id' => null, 'document_id' => $input['document_id'], 'work_record_id' => $recordId]];
        try {
            $response = DB::transaction(function () use ($principal, $recordId, $input, $key, $requestHash, $response): array {
                $linkId = $this->links->link(
                    $input['document_id'],
                    new DocumentSourceReference('work-records', 'work_record', $recordId),
                    $input['relation_type'],
                    $principal['user_id'],
                    $principal['facility_id'],
                );
                $response['data']['id'] = $linkId;
                $this->idempotency->store(
                    $principal['user_id'],
                    (string) $principal['facility_id'],
                    self::OPERATION,
                    $key,
                    $requestHash,
                    $recordId,
                    $response,
                );

                return $response;
            });
        } catch (DomainException $exception) {
            if ($exception instanceof WorkRecordIdempotencyConflict) {
                return $this->problem(409, 'idempotency-conflict', 'Conflict', 'Idempotency-Key was already used for a different request.', $correlationId);
            }

            return $this->problem(404, 'resource-not-found', 'Not Found', 'The document is not available.', $correlationId);
        }

        return response()->json($response, 201);
    }

    /** @param array{user_id: string, facility_id: string} $principal */
    private function actor(array $principal, string $correlationId): array
    {
        return [
            'user_id' => $principal['user_id'],
            'facility_id' => $principal['facility_id'],
            'organization_unit_ids' => array_filter([$principal['facility_id']]),
            'correlation_id' => $correlationId,
        ];
    }

    private function correlationId(Request $request): ?string
    {
        $value = $request->header('X-Correlation-ID');

        return is_string($value) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) === 1
            ? $value
            : null;
    }

    private function problem(int $status, string $type, string $title, string $detail, ?string $correlationId = null): JsonResponse
    {
        $response = response()->json([
            'type' => "https://cluster.example/problems/{$type}",
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ], $status)->header('Content-Type', 'application/problem+json');

        return $correlationId === null ? $response : $response->header('X-Correlation-ID', $correlationId);
    }
}
