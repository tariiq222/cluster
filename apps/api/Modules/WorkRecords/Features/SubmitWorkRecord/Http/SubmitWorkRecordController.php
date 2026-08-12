<?php

namespace Modules\WorkRecords\Features\SubmitWorkRecord\Http;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\AccessProjection;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\WorkDefinitions\Contracts\ResolvePublishedWorkDefinition;
use Modules\WorkRecords\Application\WorkRecordResourceFacts;
use Modules\WorkRecords\Domain\WorkRecord;
use Modules\WorkRecords\Features\SubmitWorkRecord\Handler\SubmitWorkRecordHandler;
use UnexpectedValueException;

final class SubmitWorkRecordController
{
    private const OPERATION = 'createWorkRecord';

    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly ResolvePublishedWorkDefinition $workDefinitions,
        private readonly DecideAccess $access,
        private readonly SubmitWorkRecordHandler $handler,
        private readonly WorkRecordResourceFacts $factsBuilder,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $correlationId = $this->correlationId($request);
        if ($correlationId === null) {
            return $this->problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }

        $idempotencyKey = $request->header('Idempotency-Key');
        if (! is_string($idempotencyKey) || preg_match('/\A[\x21-\x7E]{1,255}\z/', $idempotencyKey) !== 1) {
            return $this->problem(400, 'invalid-idempotency-key', 'Bad Request', 'Idempotency-Key is required.', $correlationId);
        }

        $principal = $this->principalResolver->resolve($request);
        if ($principal === null) {
            return $this->problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }

        $input = $request->json()->all();
        $allowedFields = ['work_definition_code', 'title', 'description'];
        $validator = Validator::make($input, [
            'work_definition_code' => ['required', 'string', 'regex:/\A[a-z][a-z0-9-]{1,95}\z/'],
            'title' => ['required', 'string', 'min:1', 'max:255'],
            'description' => ['required', 'string', 'min:1', 'max:4000'],
        ]);
        if ($validator->fails() || array_diff(array_keys($input), $allowedFields) !== []) {
            return $this->problem(422, 'invalid-work-record', 'Unprocessable Content', 'The request body is invalid.', $correlationId);
        }

        $validated = $validator->validated();
        $requestSemantics = [
            'work_definition_code' => $validated['work_definition_code'],
            'title' => $validated['title'],
            'description' => $validated['description'],
        ];
        $idempotency = [
            'principal_id' => $principal['user_id'],
            'facility_id' => $principal['facility_id'],
            'operation' => self::OPERATION,
            'key_hash' => hash('sha256', $idempotencyKey),
            'request_hash' => hash('sha256', json_encode($requestSemantics, JSON_THROW_ON_ERROR)),
        ];

        try {
            $replay = $this->handler->findReplay($idempotency);
        } catch (UnexpectedValueException) {
            return $this->problem(500, 'idempotency-state-unavailable', 'Internal Server Error', 'The request cannot be safely replayed.', $correlationId);
        }

        if ($replay !== null) {
            return $this->replay($replay, $principal, $correlationId);
        }

        $definition = $this->workDefinitions->resolve($validated['work_definition_code']);
        if ($definition === null) {
            return $this->problem(422, 'invalid-work-record', 'Unprocessable Content', 'The requested work definition is unavailable.', $correlationId);
        }
        if (array_diff(['title', 'description'], $definition['fields']) !== []) {
            return $this->problem(422, 'invalid-work-record', 'Unprocessable Content', 'The requested work definition does not accept this payload.', $correlationId);
        }
        $facts = $this->factsBuilder->forFacility(
            facilityId: $principal['facility_id'],
            classification: $definition['classification'],
            createdByUserId: $principal['user_id'],
            lifecycleState: 'submitted',
            fieldPolicyKey: $definition['field_policy_key'] ?? null,
            workTypeVersionId: $definition['version_id'],
            lockVersion: 1,
        );
        $decision = $this->access->decide($this->actor($principal), 'work_record.submit', $facts);
        if (! $decision->isAllowed()) {
            return $this->problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }

        $submittedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $record = WorkRecord::submit(
            id: Str::uuid7()->toString(),
            recordNumber: 'WR-'.strtoupper(str_replace('-', '', Str::uuid7()->toString())),
            workTypeVersionId: $definition['version_id'],
            ownerFacilityId: $principal['facility_id'],
            creatorUserId: $principal['user_id'],
            classification: $definition['classification'],
            payload: [
                'title' => $validated['title'],
                'description' => $validated['description'],
            ],
            submittedAt: $submittedAt,
            fieldPolicyKey: $definition['field_policy_key'] ?? null,
        );
        $envelope = $record->toEnvelope();
        try {
            $result = $this->handler->persist($record, [
                'specversion' => '1.0',
                'id' => Str::uuid7()->toString(),
                'source' => '/work-records',
                'type' => 'com.cluster.workrecord.submitted.v1',
                'subject' => '/work-records/'.$envelope['id'],
                'time' => $envelope['submitted_at'],
                'datacontenttype' => 'application/json',
                'correlationid' => $correlationId,
                'data' => [
                    'record' => $envelope,
                    'access_context' => ['owner_facility_id' => $principal['facility_id']],
                    'classification' => $definition['classification'],
                ],
            ], $idempotency);
        } catch (UnexpectedValueException) {
            return $this->problem(500, 'idempotency-state-unavailable', 'Internal Server Error', 'The request cannot be safely replayed.', $correlationId);
        }

        return $result['created']
            ? $this->created($result['record'], $correlationId, AccessProjection::fromDecision($decision))
            : $this->replay($result, $principal, $correlationId);
    }

    /**
     * @param  array{created: bool, request_hash_matches: bool, record: array<string, mixed>}  $result
     * @param  array{user_id: string, facility_id: string}  $principal
     */
    private function replay(array $result, array $principal, string $correlationId): JsonResponse
    {
        $record = $result['record'];
        $facts = $this->factsBuilder->forRecord($record);
        $decision = $this->access->decide($this->actor($principal), 'work_record.read', $facts);
        if (! $decision->isAllowed()) {
            return $this->problem(404, 'work-record-unavailable', 'Not Found', 'لا يمكنك فتح هذا الطلب أو لم يعد متاحاً.', $correlationId);
        }

        if (! $result['request_hash_matches']) {
            return $this->problem(409, 'idempotency-conflict', 'Conflict', 'Idempotency-Key was already used for a different request.', $correlationId);
        }

        return $this->created($record, $correlationId, AccessProjection::fromDecision($decision));
    }

    /** @param array<string, mixed> $record */
    private function created(array $record, string $correlationId, ?AccessProjection $projection = null): JsonResponse
    {
        if ($projection !== null) {
            $record = $projection->compose($record, function (array $payload, array $fieldAccess): array {
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
        }

        return response()->json(['data' => $record], 201)->withHeaders([
            'X-Correlation-ID' => $correlationId,
            'ETag' => '"'.$record['lock_version'].'"',
        ]);
    }

    /** @param array{user_id: string, facility_id: string} $principal */
    private function actor(array $principal): array
    {
        return [
            'user_id' => $principal['user_id'],
            'facility_id' => $principal['facility_id'],
            'organization_unit_ids' => array_filter([$principal['facility_id']]),
            'correlation_id' => null,
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
