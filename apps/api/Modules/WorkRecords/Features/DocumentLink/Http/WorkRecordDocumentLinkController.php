<?php

namespace Modules\WorkRecords\Features\DocumentLink\Http;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Documents\Contracts\DocumentSourceReference;
use Modules\Documents\Contracts\LinkDocument;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\WorkRecords\Domain\WorkRecordIdempotencyConflict;
use Modules\WorkRecords\Infrastructure\Persistence\WorkRecordDocumentLinkIdempotency;

final class WorkRecordDocumentLinkController
{
    private const OPERATION = 'attachWorkRecordDocument';

    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principals,
        private readonly LinkDocument $links,
        private readonly WorkRecordDocumentLinkIdempotency $idempotency,
    ) {}

    public function __invoke(Request $request, string $recordId): JsonResponse
    {
        $principal = $this->principals->resolve($request);
        if ($principal === null) {
            return response()->json(['type' => 'about:blank', 'title' => 'Unauthorized', 'status' => 401], 401);
        }
        $validator = Validator::make($request->json()->all(), [
            'document_id' => ['required', 'uuid'],
            'relation_type' => ['required', 'string', 'in:attachment,evidence'],
        ]);
        if ($validator->fails()) {
            return response()->json(['type' => 'about:blank', 'title' => 'Invalid document link', 'status' => 422], 422);
        }
        $input = $validator->validated();

        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || preg_match('/\A[\x21-\x7E]{1,255}\z/', $key) !== 1) {
            return response()->json(['type' => 'about:blank', 'title' => 'Idempotency-Key is required', 'status' => 400], 400);
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
            return response()->json(['type' => 'about:blank', 'title' => 'Idempotency-Key was already used for a different request', 'status' => 409], 409);
        }
        if ($replay !== null) {
            return response()->json($replay, 201);
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
                return response()->json(['type' => 'about:blank', 'title' => 'Idempotency-Key was already used for a different request', 'status' => 409], 409);
            }

            return response()->json(['type' => 'about:blank', 'title' => 'Document unavailable', 'status' => 404], 404);
        }

        return response()->json($response, 201);
    }
}
