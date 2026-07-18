<?php

namespace Modules\Organization\Features\ImportJob\Handler;

use Closure;
use DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Modules\Organization\Contracts\ResolveQuarantinedImport;
use Modules\Organization\Features\Assignment\Handler\AssignmentHandler;
use Modules\Organization\Features\CreateFacility\Handler\CreateFacilityHandler;
use Modules\Organization\Features\ImportJob\Template\FacilitiesImportTemplate;
use Modules\Organization\Features\ImportJob\Template\GovernedImportTemplate;
use Modules\Organization\Features\ImportJob\Template\ImportBatchContext;
use Modules\Organization\Features\ImportJob\Template\OrganizationUnitsImportTemplate;
use Modules\Organization\Features\ImportJob\Template\PeopleAssignmentsImportTemplate;
use Modules\Organization\Features\ImportJob\Template\PositionsImportTemplate;
use Modules\Organization\Features\OrganizationUnit\Handler\OrganizationUnitHandler;
use Modules\Organization\Features\Person\Handler\PersonHandler;
use Modules\Organization\Features\Position\Handler\PositionHandler;
use Modules\Organization\Infrastructure\Outbox\OrganizationOutbox;
use stdClass;
use UnexpectedValueException;

final class ImportJobHandler
{
    private const MAX_ROWS = 1000;

    public function __construct(
        private readonly ResolveQuarantinedImport $source,
        private readonly PersonHandler $people,
        private readonly AssignmentHandler $assignments,
        private readonly CreateFacilityHandler $facilities,
        private readonly OrganizationUnitHandler $units,
        private readonly PositionHandler $positions,
        private readonly OrganizationOutbox $outbox,
    ) {}

    /**
     * @param  array{quarantine_object_id: string, template_code: string, import_type: string, notes?: string|null}  $input
     * @param  array{principal_id: string, operation: string, key_hash: string, request_hash: string}  $idempotency
     * @param  Closure(string, string, array<string, mixed>, string): array<string, mixed>  $eventFactory
     * @return array{request_hash_matches: bool, job: array<string, mixed>}
     */
    public function submit(string $jobId, array $input, array $idempotency, Closure $eventFactory): array
    {
        return DB::transaction(function () use ($jobId, $input, $idempotency, $eventFactory): array {
            $existing = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
            if ($existing instanceof stdClass) {
                return $this->replay($existing, $idempotency['request_hash']);
            }
            $concurrent = $this->claimIdempotency($jobId, $idempotency);
            if ($concurrent !== null) {
                return $concurrent;
            }
            DB::table('import_jobs')->insert([
                'id' => $jobId,
                'template_code' => $input['template_code'],
                'source_filename' => null,
                'source_format' => $input['import_type'],
                'status' => 'received',
                'quarantine_object_id' => $input['quarantine_object_id'],
                'submitted_by_user_id' => $idempotency['principal_id'],
                'approved_by_user_id' => null,
                'total_rows' => 0,
                'valid_rows' => 0,
                'error_rows' => 0,
                'notes' => $input['notes'] ?? null,
                'decision_reason' => null,
                'applied_at' => null,
                'lock_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $job = $this->find($jobId);
            if ($job === null) {
                throw new UnexpectedValueException('The import job write could not be read back.');
            }
            $this->storeReplay($idempotency, $job);
            $this->outbox->insert($eventFactory(
                'com.cluster.organization.importjobsubmitted.v1',
                '/organization/import-jobs/'.$jobId,
                ['import_job' => $job],
                'internal',
            ), $jobId);

            return ['request_hash_matches' => true, 'job' => $job];
        });
    }

    /** @return array<string, mixed>|null */
    public function find(string $jobId): ?array
    {
        $row = DB::table('import_jobs')->where('id', $jobId)->first();

        return $row instanceof stdClass ? $this->serializeJob($row) : null;
    }

    /** @return array{items: list<array<string, mixed>>, next_cursor: string|null} */
    public function listRows(string $jobId, ?string $cursor, int $limit): array
    {
        if (! DB::table('import_jobs')->where('id', $jobId)->exists()) {
            throw new DomainException('import_job_not_found');
        }
        $after = $cursor === null ? null : $this->decodeCursor($cursor, $jobId, $limit);
        $query = DB::table('import_rows')->where('import_job_id', $jobId)->orderBy('row_number');
        if ($after !== null) {
            $query->where('row_number', '>', $after);
        }
        $rows = $query->limit($limit + 1)->get()->all();
        $hasNextPage = count($rows) > $limit;
        if ($hasNextPage) {
            array_pop($rows);
        }
        $items = array_map(fn (stdClass $row): array => $this->serializeRow($row), $rows);

        return [
            'items' => $items,
            'next_cursor' => $hasNextPage
                ? $this->encodeCursor((int) $items[array_key_last($items)]['row_number'], $jobId, $limit)
                : null,
        ];
    }

    /**
     * @param  array{principal_id: string, operation: string, key_hash: string, request_hash: string}  $idempotency
     * @param  Closure(string, string, array<string, mixed>, string): array<string, mixed>  $eventFactory
     * @return array{request_hash_matches: bool, job: array<string, mixed>}
     */
    public function transition(
        string $jobId,
        string $action,
        int $expectedVersion,
        string $principalId,
        ?string $reason,
        array $idempotency,
        Closure $eventFactory,
    ): array {
        $existing = $this->idempotencyQuery($idempotency)->first();
        if ($existing instanceof stdClass) {
            return $this->replay($existing, $idempotency['request_hash']);
        }

        $resolved = null;
        if ($action === 'validate') {
            $snapshot = DB::table('import_jobs')->where('id', $jobId)->first();
            if ($snapshot instanceof stdClass) {
                $resolved = $this->source->resolve(
                    (string) $snapshot->quarantine_object_id,
                    (string) $snapshot->template_code,
                    (string) $snapshot->source_format,
                );
            }
        }

        return DB::transaction(function () use ($jobId, $action, $expectedVersion, $principalId, $reason, $idempotency, $eventFactory, $resolved): array {
            $existing = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
            if ($existing instanceof stdClass) {
                return $this->replay($existing, $idempotency['request_hash']);
            }
            $concurrent = $this->claimIdempotency($jobId, $idempotency);
            if ($concurrent !== null) {
                return $concurrent;
            }
            $row = DB::table('import_jobs')->where('id', $jobId)->lockForUpdate()->first();
            if (! $row instanceof stdClass) {
                throw new DomainException('import_job_not_found');
            }
            if ((int) $row->lock_version !== $expectedVersion) {
                throw new DomainException('precondition_failed');
            }

            $eventType = match ($action) {
                'validate' => $this->validate($row, $resolved),
                'approve' => $this->approve($row, $principalId),
                'reject' => $this->reject($row, $reason),
                'apply' => $this->apply($row, $principalId, $eventFactory),
                'cancel' => $this->cancel($row, $reason),
                default => throw new InvalidArgumentException('import_action_invalid'),
            };
            $job = $this->find($jobId);
            if ($job === null) {
                throw new UnexpectedValueException('The import job transition could not be read back.');
            }
            $this->storeReplay($idempotency, $job);
            $this->outbox->insert($eventFactory(
                $eventType,
                '/organization/import-jobs/'.$jobId,
                ['import_job' => $job],
                'internal',
            ), $jobId);

            return ['request_hash_matches' => true, 'job' => $job];
        });
    }

    /** @param array{source_filename: string, rows: list<array<string, mixed>>}|null $resolved */
    private function validate(stdClass $job, ?array $resolved): string
    {
        if ($job->status !== 'received') {
            throw new DomainException('import_transition_invalid');
        }
        if ($resolved === null || count($resolved['rows']) === 0 || count($resolved['rows']) > self::MAX_ROWS) {
            $this->updateJob($job, [
                'status' => 'failed',
                'decision_reason' => 'quarantine_source_unavailable',
                'error_rows' => 1,
            ]);

            return 'com.cluster.organization.importjobfailed.v1';
        }
        $template = $this->template((string) $job->template_code);
        if ($template === null) {
            $this->updateJob($job, [
                'status' => 'failed',
                'decision_reason' => 'template_not_implemented',
                'error_rows' => count($resolved['rows']),
            ]);

            return 'com.cluster.organization.importjobfailed.v1';
        }

        $valid = 0;
        $errors = 0;
        $critical = false;
        $baseValidations = [];
        $eligibleRows = [];
        foreach ($resolved['rows'] as $offset => $payload) {
            $baseValidations[$offset] = $template->validate($payload);
            if ($baseValidations[$offset] === []) {
                $eligibleRows[$offset] = $payload;
            }
        }
        $context = ImportBatchContext::from((string) $job->template_code, $eligibleRows);
        foreach ($resolved['rows'] as $offset => $payload) {
            $validation = $baseValidations[$offset];
            if ($validation === []) {
                $validation = $template->validateBatch($payload, $context, $offset + 1);
            }
            $rowCritical = in_array('critical', array_column($validation, 'severity'), true);
            $accepted = $validation === [];
            $valid += $accepted ? 1 : 0;
            $errors += $accepted ? 0 : 1;
            $critical = $critical || $rowCritical;
            DB::table('import_rows')->insert([
                'id' => Str::uuid7()->toString(),
                'import_job_id' => $job->id,
                'row_number' => $offset + 1,
                'encrypted_payload' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
                'proposed_action' => $accepted ? 'create' : 'skip',
                'proposed_target_id' => null,
                'validation_errors' => $validation === [] ? null : json_encode($validation, JSON_THROW_ON_ERROR),
                'decision' => $accepted ? 'accepted' : 'rejected',
                'applied_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $status = $critical ? 'failed' : 'validated';
        $this->updateJob($job, [
            'source_filename' => $resolved['source_filename'],
            'status' => $status,
            'total_rows' => count($resolved['rows']),
            'valid_rows' => $valid,
            'error_rows' => $errors,
            'decision_reason' => $critical ? 'critical_validation_error' : null,
        ]);

        return $critical
            ? 'com.cluster.organization.importjobfailed.v1'
            : 'com.cluster.organization.importjobvalidated.v1';
    }

    private function approve(stdClass $job, string $principalId): string
    {
        if ($job->status !== 'validated') {
            throw new DomainException('import_transition_invalid');
        }
        if (hash_equals((string) $job->submitted_by_user_id, $principalId)) {
            throw new DomainException('import_dual_approval_required');
        }
        $this->updateJob($job, [
            'status' => 'approved',
            'approved_by_user_id' => $principalId,
        ]);

        return 'com.cluster.organization.importjobapproved.v1';
    }

    private function reject(stdClass $job, ?string $reason): string
    {
        if ($job->status !== 'validated' || $reason === null) {
            throw new DomainException('import_transition_invalid');
        }
        $this->updateJob($job, ['status' => 'rejected', 'decision_reason' => $reason]);

        return 'com.cluster.organization.importjobrejected.v1';
    }

    /** @param Closure(string, string, array<string, mixed>, string): array<string, mixed> $eventFactory */
    private function apply(stdClass $job, string $principalId, Closure $eventFactory): string
    {
        if ($job->status !== 'approved' || $job->approved_by_user_id === null) {
            throw new DomainException('import_transition_invalid');
        }
        $template = $this->template((string) $job->template_code);
        if ($template === null) {
            throw new DomainException('import_transition_invalid');
        }
        $rows = DB::table('import_rows')
            ->where('import_job_id', $job->id)
            ->where('decision', 'accepted')
            ->orderBy('row_number')
            ->lockForUpdate()
            ->get();
        foreach ($rows as $row) {
            $payload = $this->decryptPayload($row);
            $targetId = $template->apply(
                (string) $row->id,
                $payload,
                $principalId,
                $eventFactory,
                fn (string $operation, array $rowPayload): array => $this->rowIdempotency($principalId, $operation, $rowPayload),
            );
            DB::table('import_rows')->where('id', $row->id)->update([
                'proposed_target_id' => $targetId,
                'applied_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->updateJob($job, [
            'status' => 'applied',
            'applied_at' => now(),
        ]);

        return 'com.cluster.organization.importjobapplied.v1';
    }

    private function cancel(stdClass $job, ?string $reason): string
    {
        if ($job->status !== 'approved' || $reason === null) {
            throw new DomainException('import_transition_invalid');
        }
        $this->updateJob($job, ['status' => 'cancelled', 'decision_reason' => $reason]);

        return 'com.cluster.organization.importjobcancelled.v1';
    }

    private function template(string $templateCode): ?GovernedImportTemplate
    {
        return match ($templateCode) {
            'facilities' => new FacilitiesImportTemplate($this->facilities),
            'organization_units' => new OrganizationUnitsImportTemplate($this->units),
            'positions' => new PositionsImportTemplate($this->positions),
            'people_assignments' => new PeopleAssignmentsImportTemplate($this->people, $this->assignments),
            default => null,
        };
    }

    /** @param array<string, mixed> $changes */
    private function updateJob(stdClass $job, array $changes): void
    {
        $updated = DB::table('import_jobs')
            ->where('id', $job->id)
            ->where('lock_version', $job->lock_version)
            ->update([
                ...$changes,
                'lock_version' => (int) $job->lock_version + 1,
                'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            throw new DomainException('precondition_failed');
        }
    }

    /** @return array<string, mixed> */
    private function decryptPayload(stdClass $row): array
    {
        try {
            $payload = json_decode(Crypt::decryptString((string) $row->encrypted_payload), true, 32, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new UnexpectedValueException('Stored import row payload is unavailable.');
        }
        if (! is_array($payload)) {
            throw new UnexpectedValueException('Stored import row payload is invalid.');
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload @return array{principal_id: string, operation: string, key_hash: string, request_hash: string} */
    private function rowIdempotency(string $principalId, string $operation, array $payload): array
    {
        return [
            'principal_id' => $principalId,
            'operation' => $operation,
            'key_hash' => hash('sha256', $operation),
            'request_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        ];
    }

    /** @param array{principal_id: string, operation: string, key_hash: string, request_hash: string} $idempotency @return array{request_hash_matches: bool, job: array<string, mixed>}|null */
    private function claimIdempotency(string $jobId, array $idempotency): ?array
    {
        $claimed = DB::table('organization_idempotency_keys')->insertOrIgnore([
            'principal_id' => $idempotency['principal_id'],
            'operation' => $idempotency['operation'],
            'idempotency_key_hash' => $idempotency['key_hash'],
            'request_hash' => $idempotency['request_hash'],
            'resource_type' => 'import_job',
            'resource_id' => $jobId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($claimed) {
            return null;
        }
        $concurrent = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
        if (! $concurrent instanceof stdClass) {
            throw new UnexpectedValueException('The import idempotency claim could not be resolved.');
        }

        return $this->replay($concurrent, $idempotency['request_hash']);
    }

    /** @param array{principal_id: string, operation: string, key_hash: string, request_hash: string} $idempotency */
    private function idempotencyQuery(array $idempotency): mixed
    {
        return DB::table('organization_idempotency_keys')
            ->where('principal_id', $idempotency['principal_id'])
            ->where('operation', $idempotency['operation'])
            ->where('idempotency_key_hash', $idempotency['key_hash']);
    }

    /** @param array{principal_id: string, operation: string, key_hash: string, request_hash: string} $idempotency @param array<string, mixed> $job */
    private function storeReplay(array $idempotency, array $job): void
    {
        $this->idempotencyQuery($idempotency)->update([
            'response_payload' => json_encode($job, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    /** @return array{request_hash_matches: bool, job: array<string, mixed>} */
    private function replay(stdClass $key, string $requestHash): array
    {
        if (! is_string($key->response_payload)) {
            throw new UnexpectedValueException('Stored import idempotency state is incomplete.');
        }
        try {
            $job = json_decode($key->response_payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UnexpectedValueException('Stored import idempotency response is invalid.');
        }
        if (! is_array($job)) {
            throw new UnexpectedValueException('Stored import idempotency response is invalid.');
        }

        return [
            'request_hash_matches' => is_string($key->request_hash) && hash_equals($key->request_hash, $requestHash),
            'job' => $job,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeJob(stdClass $row): array
    {
        return [
            'id' => $row->id,
            'template_code' => $row->template_code,
            'import_type' => $row->source_format,
            'status' => $row->status,
            'submitted_by_user_id' => $row->submitted_by_user_id,
            'approved_by_user_id' => $row->approved_by_user_id,
            'total_rows' => (int) $row->total_rows,
            'valid_rows' => (int) $row->valid_rows,
            'error_rows' => (int) $row->error_rows,
            'applied_at' => $row->applied_at === null ? null : date('Y-m-d\TH:i:s.v\Z', strtotime((string) $row->applied_at)),
            'lock_version' => (int) $row->lock_version,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeRow(stdClass $row): array
    {
        return [
            'id' => $row->id,
            'row_number' => (int) $row->row_number,
            'proposed_action' => $row->proposed_action,
            'proposed_target_id' => $row->proposed_target_id,
            'validation_errors' => $row->validation_errors === null
                ? []
                : json_decode((string) $row->validation_errors, true, 16, JSON_THROW_ON_ERROR),
            'decision' => $row->decision,
            'applied_at' => $row->applied_at === null ? null : date('Y-m-d\TH:i:s.v\Z', strtotime((string) $row->applied_at)),
        ];
    }

    private function encodeCursor(int $rowNumber, string $jobId, int $limit): string
    {
        return Crypt::encryptString(json_encode([
            'version' => 1,
            'resource' => 'import_row',
            'after' => $rowNumber,
            'job_id' => $jobId,
            'limit' => $limit,
        ], JSON_THROW_ON_ERROR));
    }

    private function decodeCursor(string $cursor, string $jobId, int $limit): int
    {
        try {
            $payload = json_decode(Crypt::decryptString($cursor), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new InvalidArgumentException('The import row cursor is invalid.');
        }
        if (! is_array($payload)
            || ($payload['version'] ?? null) !== 1
            || ($payload['resource'] ?? null) !== 'import_row'
            || ($payload['job_id'] ?? null) !== $jobId
            || ($payload['limit'] ?? null) !== $limit
            || ! is_int($payload['after'] ?? null)) {
            throw new InvalidArgumentException('The import row cursor is invalid.');
        }

        return $payload['after'];
    }
}
