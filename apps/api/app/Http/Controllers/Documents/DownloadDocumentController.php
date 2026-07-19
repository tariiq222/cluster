<?php

namespace App\Http\Controllers\Documents;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Documents\Application\DocumentAccessRequest;
use Modules\Documents\Application\DocumentDownloadService;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;

final class DownloadDocumentController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principals,
        private readonly DocumentDownloadService $service,
    ) {}

    public function __invoke(Request $request, string $documentId): RedirectResponse|JsonResponse
    {
        $correlationId = DocumentsApi::correlationId($request);
        if ($correlationId === null) {
            return DocumentsApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        if (! DocumentsApi::isUuidV7($documentId)) {
            return DocumentsApi::problem(400, 'invalid-document-upload', 'Bad Request', 'The document id is invalid.', $correlationId);
        }
        $principal = DocumentsApi::principalOrProblem($request, $this->principals, $correlationId);
        if ($principal instanceof JsonResponse) {
            return $principal;
        }
        $versionId = DB::table('document_versions as v')->join('documents as d', 'd.id', '=', 'v.document_id')
            ->where('d.public_id', $documentId)->where('v.scan_status', 'clean')->where('v.availability_status', 'available')
            ->orderByDesc('v.version_number')->value('v.public_id');
        if (! is_string($versionId)) {
            return DocumentsApi::problem(404, 'document-upload-not-found', 'Not Found', 'The document is not available.', $correlationId);
        }
        try {
            $grant = $this->service->download($documentId, $versionId, new DocumentAccessRequest(
                $principal['user_id'], $principal['facility_id'], $correlationId, $request->ip(), $request->header('X-Device-Fingerprint-Hash'),
            ));
        } catch (DomainException $exception) {
            return DocumentsApi::domainProblem($exception, $correlationId);
        }

        return redirect()->away($grant->url, 302)->header('X-Correlation-ID', $correlationId);
    }
}
