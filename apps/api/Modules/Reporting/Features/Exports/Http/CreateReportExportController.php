<?php

namespace Modules\Reporting\Features\Exports\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Reporting\Features\ExportAuthorizedReport\Handler\ExportAuthorizationDenied;
use Modules\Reporting\Features\ExportAuthorizedReport\Handler\ExportAuthorizedReportHandler;
use Modules\Reporting\Features\ExportAuthorizedReport\Handler\ExportIdempotencyConflict;
use Modules\Reporting\Features\ExportAuthorizedReport\Handler\UnsupportedExportFormatException;
use Modules\Reporting\Http\ReportingApi;

final class CreateReportExportController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly ExportAuthorizedReportHandler $exports,
    ) {}

    public function __invoke(Request $request, string $reportId): JsonResponse
    {
        $correlationId = ReportingApi::correlationId($request);
        if ($correlationId === null) {
            return ReportingApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        $principal = ReportingApi::principalOrProblem($request, $this->principalResolver, $correlationId);
        if ($principal instanceof JsonResponse) {
            return $principal;
        }
        $input = $request->json()->all();
        $requestedScopeId = is_string($input['scope_id'] ?? null) ? $input['scope_id'] : null;
        if (! $this->exports->authorize($principal, $reportId, $requestedScopeId)) {
            return ReportingApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || preg_match('/\A[\x21-\x7E]{1,255}\z/', $key) !== 1) {
            return ReportingApi::problem(400, 'invalid-idempotency-key', 'Bad Request', 'Idempotency-Key is required.', $correlationId);
        }
        ksort($input);
        $requestHash = hash('sha256', json_encode([
            'report_id' => $reportId,
            'body' => $input,
        ], JSON_THROW_ON_ERROR));
        try {
            $replay = $this->exports->findReplay($principal, $key, $requestHash);
            if ($replay !== null) {
                return ReportingApi::response($replay, 202, $correlationId);
            }
            $validator = Validator::make($input, [
                'format' => ['required', 'string'],
                'scope_id' => ['sometimes', 'string', 'max:128'],
            ]);
            if ($validator->fails() || array_diff(array_keys($input), ['format', 'scope_id']) !== []) {
                return ReportingApi::problem(400, 'invalid-export-request', 'Bad Request', 'The export request is invalid.', $correlationId);
            }
            $body = $validator->validated();

            return ReportingApi::response(
                $this->exports->handle(
                    $reportId,
                    $principal,
                    (string) $body['format'],
                    $body['scope_id'] ?? null,
                    $key,
                    $requestHash,
                ),
                202,
                $correlationId,
            );
        } catch (ExportIdempotencyConflict) {
            return ReportingApi::problem(409, 'idempotency-conflict', 'Conflict', 'Idempotency-Key was already used for a different request.', $correlationId);
        } catch (ExportAuthorizationDenied) {
            return ReportingApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        } catch (UnsupportedExportFormatException) {
            return ReportingApi::problem(422, 'unsupported-export-format', 'Unprocessable Entity', 'Only csv and json exports are supported.', $correlationId);
        }
    }
}
