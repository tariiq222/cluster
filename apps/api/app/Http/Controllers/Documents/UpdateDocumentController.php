<?php

namespace App\Http\Controllers\Documents;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;

/** PATCH /api/v1/documents/{documentId} — governed metadata update. */
final class UpdateDocumentController
{
    use DocumentAccessSupport;

    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principals,
        private readonly DecideAccess $access,
    ) {}

    public function __invoke(Request $request, string $documentId): JsonResponse
    {
        $correlationId = DocumentsApi::correlationId($request);
        if ($correlationId === null) {
            return DocumentsApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        if (! DocumentsApi::isUuidV7($documentId)) {
            return DocumentsApi::problem(400, 'invalid-document', 'Bad Request', 'The document id is invalid.', $correlationId);
        }
        $expectedVersion = DocumentsApi::ifMatch($request);
        if ($expectedVersion === null) {
            return DocumentsApi::problem(400, 'invalid-if-match', 'Bad Request', 'If-Match must contain one current strong ETag.', $correlationId);
        }
        if (! DocumentsApi::isMergePatch($request)) {
            return DocumentsApi::problem(400, 'invalid-content-type', 'Bad Request', 'Content-Type must be application/merge-patch+json.', $correlationId);
        }
        $principal = DocumentsApi::principalOrProblem($request, $this->principals, $correlationId);
        if ($principal instanceof JsonResponse) {
            return $principal;
        }

        $document = $this->findDocument($documentId);
        if ($document === null) {
            return DocumentsApi::problem(404, 'resource-not-found', 'Not Found', 'The document is not available.', $correlationId);
        }
        if (($deny = $this->decideOnDocument($principal, $this->access, $document, 'documents.update', $correlationId)) instanceof JsonResponse) {
            return $deny;
        }

        $input = $request->json()->all();
        $validator = Validator::make($input, [
            'title' => ['sometimes', 'required', 'string', 'min:1', 'max:255'],
            'description' => ['sometimes', 'required', 'string', 'max:2000'],
            'classification' => ['sometimes', 'required', 'string', 'in:public,internal,confidential,top_secret'],
            'classification_change_reason' => ['sometimes', 'required', 'string', 'min:1', 'max:2000'],
        ]);
        if ($validator->fails() || $input === [] || array_diff(array_keys($input), ['title', 'description', 'classification', 'classification_change_reason']) !== []) {
            return DocumentsApi::problem(400, 'invalid-document', 'Bad Request', 'The document patch is invalid.', $correlationId);
        }
        $validated = $validator->validated();
        $changes = array_intersect_key($validated, array_flip(['title', 'description', 'classification']));
        if ($changes === []) {
            return DocumentsApi::problem(400, 'invalid-document', 'Bad Request', 'The document patch is invalid.', $correlationId);
        }
        if (array_key_exists('title', $changes)) {
            $changes['name'] = trim((string) $changes['title']);
            unset($changes['title']);
        }
        if (array_key_exists('classification', $changes)
            && $changes['classification'] !== $document->classification
            && (! isset($validated['classification_change_reason']) || trim((string) $validated['classification_change_reason']) === '')) {
            return DocumentsApi::problem(400, 'invalid-document', 'Bad Request', 'A classification change reason is required.', $correlationId);
        }

        $now = now();
        try {
            $fresh = DB::transaction(function () use ($document, $expectedVersion, $changes, $validated, $principal, $correlationId, $now): \stdClass {
                $updated = DB::table('documents')
                    ->where('id', $document->id)
                    ->where('lock_version', $expectedVersion)
                    ->update([...$changes, 'lock_version' => $expectedVersion + 1, 'updated_at' => $now]);
                if ($updated !== 1) {
                    throw new \DomainException('precondition_failed');
                }
                DB::table('document_outbox_events')->insert([
                    'id' => Str::uuid7()->toString(),
                    'aggregate_id' => $document->id,
                    'event_type' => 'com.cluster.documents.metadataupdated.v1',
                    'payload' => json_encode([
                        'document_id' => $document->public_id,
                        'changed_fields' => array_keys($changes),
                        'classification_change_reason' => $validated['classification_change_reason'] ?? null,
                        'correlation_id' => $correlationId,
                        'actor_user_id' => $principal['user_id'],
                    ], JSON_THROW_ON_ERROR),
                    'occurred_at' => $now,
                    'published_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return DB::table('documents')->where('id', $document->id)->first();
            });
        } catch (\DomainException $exception) {
            if ($exception->getMessage() === 'precondition_failed') {
                return DocumentsApi::problem(412, 'precondition-failed', 'Precondition Failed', 'If-Match does not match the current version.', $correlationId);
            }

            return DocumentsApi::problem(409, 'document-operation-conflict', 'Conflict', 'The document cannot be updated.', $correlationId);
        } catch (QueryException) {
            return DocumentsApi::problem(409, 'document-operation-conflict', 'Conflict', 'The document cannot be updated.', $correlationId);
        }

        $this->recordAccessEvent($document, $principal['user_id'], 'metadata_update', 'allow', 'document_metadata_updated');

        return response()->json(['data' => $this->serializeDocument($fresh)])
            ->header('X-Correlation-ID', $correlationId)
            ->header('ETag', '"'.(int) $fresh->lock_version.'"');
    }
}
