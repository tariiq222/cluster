<?php

namespace Modules\WorkRecords\Features\DocumentLink\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Documents\Contracts\DocumentSourceReference;
use Modules\Documents\Contracts\LinkDocument;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;

final class WorkRecordDocumentLinkController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principals,
        private readonly LinkDocument $links,
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
        try {
            $linkId = $this->links->link(
                $input['document_id'],
                new DocumentSourceReference('work-records', 'work_record', $recordId),
                $input['relation_type'],
                $principal['user_id'],
                $principal['facility_id'],
            );
        } catch (\DomainException) {
            return response()->json(['type' => 'about:blank', 'title' => 'Document unavailable', 'status' => 404], 404);
        }

        return response()->json(['data' => ['id' => $linkId, 'document_id' => $input['document_id'], 'work_record_id' => $recordId]], 201);
    }
}
