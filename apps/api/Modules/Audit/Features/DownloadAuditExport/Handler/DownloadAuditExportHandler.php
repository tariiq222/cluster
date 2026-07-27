<?php

declare(strict_types=1);

namespace Modules\Audit\Features\DownloadAuditExport\Handler;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Modules\Audit\Contracts\AuditEventInput;
use Modules\Audit\Contracts\RecordAuditEvent;
use Modules\Audit\Domain\AuditExportProjection;
use Modules\Audit\Http\AuditApi;
use Modules\Audit\Infrastructure\Persistence\AuditExportReadStore;
use Modules\Audit\Infrastructure\Persistence\AuditExportRepository;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\PrincipalContext;
use Modules\Identity\Contracts\ResolvePrincipalContext;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a frozen Audit export descriptor as UTF-8 CSV or NDJSON.
 *
 * Hard invariants (M01 Task 5):
 *  - Re-authorizes every download against the descriptor's
 *    `principal_id` and `facility_id`. Capability denial or mismatch
 *    becomes a 404 problem so existence is concealed.
 *  - Re-redacts every projected row via
 *    {@see AuditExportProjection::project()} on the streaming path,
 *    regardless of how the row was persisted.
 *  - Caps the projection at the frozen `snapshot_recorded_at` upper
 *    bound. Events recorded after the snapshot never appear.
 *  - Emits Cache-Control: no-store and never touches object storage,
 *    local files, or the browser.
 *  - Records one immutable Audit download-attempt activity per call
 *    via {@see RecordAuditEvent}. Successful, failed, or interrupted
 *    downloads never emit AuditExportCompletedV1 and never mutate
 *    the descriptor.
 *  - Returns the same bytes for repeated downloads of the same
 *    descriptor (deterministic ordering, frozen snapshot bound).
 */
final class DownloadAuditExportHandler
{
    public const ATTEMPT_OUTCOME_DOWNLOADED = 'downloaded';

    public const ATTEMPT_OUTCOME_DENIED = 'denied';

    public const ATTEMPT_OUTCOME_NOT_FOUND = 'not_found';

    public const ATTEMPT_OUTCOME_EXPIRED = 'expired';

    public const ATTEMPT_OUTCOME_FORBIDDEN = 'forbidden';

    public const ATTEMPT_OUTCOME_FAILED = 'failed';

    public function __construct(
        private readonly ResolvePrincipalContext $principals,
        private readonly DecideAccess $access,
        private readonly AuditExportRepository $exports,
        private readonly AuditExportReadStore $reads,
        private readonly AuditExportProjection $projection,
        private readonly RecordAuditEvent $recorder,
    ) {}

    public function handle(Request $request, string $exportId, ?string $correlationId): Response
    {
        $principal = $this->principals->resolve($request);
        if ($principal === null) {
            return $this->problemResponse(401, 'authentication-required', $correlationId);
        }

        try {
            AuditEventInput::assertUuidV7($exportId, 'exportId');
        } catch (InvalidArgumentException) {
            // Malformed export id: concealed 404 with an attempt record
            // whose subjectId is null (Audit context carries the
            // redacted attempt_export_id only). The raw attempted id
            // never reaches persistence.
            $this->recordMalformedAttemptActivity(
                $principal,
                $correlationId,
                'invalid',
            );

            return $this->problemResponse(
                404,
                'audit-export-not-found',
                $correlationId,
            );
        }
        $row = $this->exports->find($exportId);
        $scope = AuditApi::scope($principal);
        if ($row === null
            || (string) $row->principal_id !== $principal->userId
            || $row->facility_id !== $scope['facility_id']) {
            return $this->recordAndProblem(
                $principal,
                $exportId,
                self::ATTEMPT_OUTCOME_NOT_FOUND,
                $correlationId,
                404,
                'audit-export-not-found',
            );
        }

        // First observation CAS: a still-`ready` descriptor whose
        // expiry is in the past moves to `expired` here, with the
        // lock_version advance that the GET handler already published.
        $row = $this->advanceExpiryIfDue($row);

        // Capability decision runs BEFORE expiry disclosure so an
        // unauthorized caller cannot observe the descriptor's expiry
        // state. Denial is concealed as a 404 problem.
        $decision = $this->access->decide(
            AuditApi::actor($principal, $scope, $correlationId),
            'audit.event.export',
            new RecordFacts(
                ownerFacilityId: $row->facility_id === null ? null : (string) $row->facility_id,
                resourceType: 'audit_export',
                classification: 'internal',
                organizationUnitId: count($scope['organization_unit_ids']) === 1
                    ? $scope['organization_unit_ids'][0]
                    : null,
                recordId: (string) $row->id,
                sharedUnitIds: $scope['organization_unit_ids'],
            ),
        );
        if (! $decision->isAllowed()) {
            return $this->recordAndProblem(
                $principal,
                $exportId,
                self::ATTEMPT_OUTCOME_FORBIDDEN,
                $correlationId,
                404,
                'audit-export-not-found',
            );
        }

        $status = (string) $row->status;
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = new DateTimeImmutable((string) $row->expires_at, new DateTimeZone('UTC'));
        if ($status === AuditExportRepository::STATUS_EXPIRED || $expiresAt <= $now) {
            return $this->recordAndProblem(
                $principal,
                $exportId,
                self::ATTEMPT_OUTCOME_EXPIRED,
                $correlationId,
                410,
                'audit-export-expired',
            );
        }
        if ($status !== AuditExportRepository::STATUS_READY) {
            return $this->recordAndProblem(
                $principal,
                $exportId,
                self::ATTEMPT_OUTCOME_FAILED,
                $correlationId,
                410,
                'audit-export-expired',
            );
        }

        $snapshot = new DateTimeImmutable((string) $row->snapshot_recorded_at, new DateTimeZone('UTC'));
        $filters = $this->decodeQuery((string) $row->query);

        $response = new StreamedResponse(
            $this->streamCallback(
                $principal,
                $exportId,
                $row,
                $snapshot,
                $filters,
                $correlationId,
            ),
            200,
            $this->streamHeaders((string) $row->format, $exportId, $expiresAt),
        );

        return $response;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function streamCallback(
        PrincipalContext $principal,
        string $exportId,
        object $row,
        DateTimeImmutable $snapshot,
        array $filters,
        ?string $correlationId,
    ): Closure {
        $projection = $this->projection;
        $reads = $this->reads;
        $format = (string) $row->format;
        $scope = AuditApi::scope($principal);
        $occurredFrom = $this->filterDate($filters['occurred_from'] ?? null);
        $occurredTo = $this->filterDate($filters['occurred_to'] ?? null);

        return function () use (
            $principal,
            $exportId,
            $snapshot,
            $filters,
            $projection,
            $reads,
            $format,
            $scope,
            $occurredFrom,
            $occurredTo,
            $correlationId,
        ): void {
            try {
                if ($format === 'csv') {
                    echo $projection->csvHeader();
                }
                $reads->streamAuthorizedSnapshot(
                    function (object $raw) use ($projection, $format): bool {
                        $projected = $projection->project($raw);
                        if ($format === 'csv') {
                            echo $projection->toCsvLine($projected);
                        } else {
                            echo $projection->toNdjsonLine($projected);
                        }

                        return true;
                    },
                    $snapshot,
                    $filters['source_module'] ?? null,
                    $filters['action'] ?? null,
                    $filters['correlation_id'] ?? null,
                    $occurredFrom,
                    $occurredTo,
                    $principal->userId,
                    $scope['facility_id'],
                    $scope['organization_unit_ids'],
                );
            } catch (\Throwable $exception) {
                $this->recordAttemptActivity(
                    $principal,
                    $exportId,
                    self::ATTEMPT_OUTCOME_FAILED,
                    $correlationId,
                );
                throw $exception;
            }

            $this->recordAttemptActivity(
                $principal,
                $exportId,
                connection_aborted() === 0
                    ? self::ATTEMPT_OUTCOME_DOWNLOADED
                    : self::ATTEMPT_OUTCOME_FAILED,
                $correlationId,
            );
        };
    }

    /** @return array<string, string> */
    private function streamHeaders(string $format, string $exportId, DateTimeImmutable $expiresAt): array
    {
        $mime = $format === 'csv'
            ? 'text/csv; charset=utf-8'
            : 'application/x-ndjson; charset=utf-8';
        $filename = sprintf('audit-export-%s.%s', $exportId, $format);

        return [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Audit-Export-Id' => $exportId,
            'X-Audit-Export-Expires-At' => $expiresAt->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeQuery(string $raw): array
    {
        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function filterDate(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== ''
            ? new DateTimeImmutable($value, new DateTimeZone('UTC'))
            : null;
    }

    private function problemResponse(int $status, string $type, ?string $correlationId, ?string $detailOverride = null): Response
    {
        return AuditApi::problem(
            $status,
            $type,
            $this->titleFor($status),
            $detailOverride ?? $this->detailFor($type),
            $correlationId,
        );
    }

    private function recordAndProblem(
        PrincipalContext $principal,
        string $exportId,
        string $outcome,
        ?string $correlationId,
        int $status,
        string $type,
    ): Response {
        $this->recordAttemptActivity($principal, $exportId, $outcome, $correlationId);

        return $this->problemResponse($status, $type, $correlationId);
    }

    private function recordAttemptActivity(
        PrincipalContext $principal,
        string $exportId,
        string $outcome,
        ?string $correlationId,
    ): void {
        $occurredAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->recorder->record(new AuditEventInput(
            eventId: (string) Str::uuid7(),
            sourceModule: 'audit',
            action: 'audit.export.downloaded',
            eventType: 'com.cluster.audit.auditeventrecorded.v1',
            actorType: AuditEventInput::ACTOR_USER,
            actorId: $principal->userId,
            originalActorId: null,
            subjectType: 'audit_export',
            subjectId: $exportId,
            correlationId: is_string($correlationId) && $correlationId !== ''
                ? $correlationId
                : '00000000-0000-7000-8000-000000000000',
            outcome: $outcome === self::ATTEMPT_OUTCOME_DOWNLOADED
                ? AuditEventInput::OUTCOME_SUCCEEDED
                : AuditEventInput::OUTCOME_FAILED,
            classification: AuditEventInput::CLASSIFICATION_INTERNAL,
            context: [
                'export_id' => $exportId,
                'attempt_outcome' => $outcome,
            ],
            occurredAt: $occurredAt,
            retentionClass: AuditEventInput::RETENTION_SECURITY,
        ));
    }


    /**
     * Record a download attempt whose export id never validated. The
     * event carries `subjectId = null` so the audit_events row cannot
     * be joined to a stored export; only the redacted attempt shape
     * is persisted in context.
     */
    private function recordMalformedAttemptActivity(
        PrincipalContext $principal,
        ?string $correlationId,
        string $reason,
    ): void {
        $occurredAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->recorder->record(new AuditEventInput(
            eventId: (string) Str::uuid7(),
            sourceModule: 'audit',
            action: 'audit.export.downloaded',
            eventType: 'com.cluster.audit.auditeventrecorded.v1',
            actorType: AuditEventInput::ACTOR_USER,
            actorId: $principal->userId,
            originalActorId: null,
            subjectType: 'audit_export',
            subjectId: null,
            correlationId: is_string($correlationId) && $correlationId !== ''
                ? $correlationId
                : '00000000-0000-7000-8000-000000000000',
            outcome: AuditEventInput::OUTCOME_FAILED,
            classification: AuditEventInput::CLASSIFICATION_INTERNAL,
            context: [
                'attempt_outcome' => self::ATTEMPT_OUTCOME_NOT_FOUND,
                'attempt_export_id_invalid' => true,
                'attempt_export_id_reason' => $reason,
            ],
            occurredAt: $occurredAt,
            retentionClass: AuditEventInput::RETENTION_SECURITY,
        ));
    }

    /**
     * Move a still-`ready` descriptor whose `expires_at` is in the
     * past to `expired` exactly once. Returns the freshest row so
     * the streamed download agrees with the GET handler's response.
     */
    private function advanceExpiryIfDue(object $row): object
    {
        if ((string) $row->status !== AuditExportRepository::STATUS_READY) {
            return $row;
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = new DateTimeImmutable((string) $row->expires_at, new DateTimeZone('UTC'));
        if ($expiresAt > $now) {
            return $row;
        }
        $expected = (int) $row->lock_version;
        $this->exports->markExpired((string) $row->id, $expected);
        $fresh = $this->exports->find((string) $row->id);

        return $fresh ?? $row;
    }

    private function titleFor(int $status): string
    {
        return match ($status) {
            401 => 'Unauthorized',
            404 => 'Not Found',
            410 => 'Gone',
            default => 'Error',
        };
    }

    private function detailFor(string $type): string
    {
        return match ($type) {
            'authentication-required' => 'Authentication is required.',
            'audit-export-not-found' => 'The audit export was not found.',
            'audit-export-expired' => 'The audit export has expired and can no longer be downloaded.',
            default => 'The audit export could not be downloaded.',
        };
    }
}
