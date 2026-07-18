<?php

namespace Modules\Organization\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Modules\Organization\Contracts\ResolveQuarantinedImport;
use Tests\TestCase;

class OrganizationImportHttpAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000601';

    public const QUARANTINE_ID = '018f6f7d-0c00-7000-8000-000000000690';

    public function test_import_job_is_submitted_read_and_replayed_without_exposing_quarantine_data(): void
    {
        $token = $this->loginToken();
        $body = $this->submitBody();

        $submitted = $this->withToken($token)
            ->postJson('/api/v1/organization/import-jobs', $body, $this->writeHeaders('import-submit'))
            ->assertStatus(202)
            ->assertHeader('ETag', '"1"')
            ->assertJsonPath('data.status', 'received')
            ->assertJsonPath('data.template_code', 'people_assignments')
            ->assertJsonMissingPath('data.quarantine_object_id');
        $jobId = $submitted->json('data.id');
        $this->assertIsString($jobId);

        $replay = $this->withToken($token)
            ->postJson('/api/v1/organization/import-jobs', $body, $this->writeHeaders('import-submit'))
            ->assertStatus(202);
        $this->assertSame($jobId, $replay->json('data.id'));
        $this->withToken($token)
            ->postJson('/api/v1/organization/import-jobs', [...$body, 'notes' => 'different'], $this->writeHeaders('import-submit'))
            ->assertConflict();
        $this->withToken($token)
            ->getJson('/api/v1/organization/import-jobs/'.$jobId, $this->headers())
            ->assertOk()
            ->assertHeader('ETag', '"1"')
            ->assertJsonPath('data.id', $jobId)
            ->assertJsonMissingPath('data.quarantine_object_id');

        $this->assertDatabaseHas('import_jobs', ['id' => $jobId, 'quarantine_object_id' => self::QUARANTINE_ID]);
        $eventRow = DB::table('outbox_events')
            ->where('aggregate_id', $jobId)
            ->where('event_type', 'com.cluster.organization.importjobsubmitted.v1')
            ->first();
        $this->assertNotNull($eventRow);
        $event = json_decode((string) $eventRow->cloud_event, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($jobId, $event['data']['import_job']['id']);
        $this->assertSame('internal', $event['data']['classification']);
        $encoded = json_encode($event, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('quarantine_object_id', $encoded);
        $this->assertStringNotContainsString(self::QUARANTINE_ID, $encoded);
        $this->assertStringNotContainsString('raw_payload', $encoded);
    }

    public function test_validation_encrypts_rows_and_lists_only_redacted_results(): void
    {
        $token = $this->loginToken();
        $positionId = $this->positionReference($token);
        $source = $this->bindSource([
            $this->validRow($positionId, 'EMP-IMPORT-001'),
            [...$this->validRow($positionId, 'EMP-IMPORT-002'), 'status' => 'unknown'],
        ]);
        $jobId = $this->submit($token, 'import-validate-submit');

        $this->withToken($token)
            ->postJson("/api/v1/organization/import-jobs/{$jobId}/validate", [], $this->actionHeaders('"1"', 'import-validate'))
            ->assertOk()
            ->assertHeader('ETag', '"2"')
            ->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.total_rows', 2)
            ->assertJsonPath('data.valid_rows', 1)
            ->assertJsonPath('data.error_rows', 1);
        $this->withToken($token)
            ->postJson("/api/v1/organization/import-jobs/{$jobId}/validate", [], $this->actionHeaders('"1"', 'import-validate'))
            ->assertOk()
            ->assertHeader('ETag', '"2"');
        $this->assertSame(1, $source->calls);

        $stored = DB::table('import_rows')->orderBy('row_number')->get();
        $this->assertCount(2, $stored);
        $this->assertStringNotContainsString('EMP-IMPORT-001', (string) $stored[0]->encrypted_payload);
        $this->assertStringContainsString('EMP-IMPORT-001', Crypt::decryptString((string) $stored[0]->encrypted_payload));
        $this->withToken($token)
            ->getJson("/api/v1/organization/import-jobs/{$jobId}/rows", $this->headers())
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.row_number', 1)
            ->assertJsonMissingPath('items.0.encrypted_payload')
            ->assertJsonMissingPath('items.0.payload')
            ->assertJsonPath('items.1.validation_errors.0.code', 'invalid_status');
    }

    public function test_dual_approval_and_apply_create_person_assignment_and_provisioning_once(): void
    {
        $submitter = $this->loginToken();
        $approver = $this->loginToken('fixture-account-b', 'fixture-password-b');
        $positionId = $this->positionReference($submitter);
        $this->bindSource([$this->validRow($positionId, 'EMP-IMPORT-APPLY')]);
        $jobId = $this->submit($submitter, 'import-apply-submit');

        $this->withToken($submitter)
            ->postJson("/api/v1/organization/import-jobs/{$jobId}/validate", [], $this->actionHeaders('"1"', 'import-apply-validate'))
            ->assertOk();
        $this->withToken($submitter)
            ->postJson("/api/v1/organization/import-jobs/{$jobId}/approve", [], $this->actionHeaders('"2"', 'import-self-approve'))
            ->assertConflict()
            ->assertJsonPath('type', 'https://cluster.example/problems/import-dual-approval-required');
        $this->withToken($approver)
            ->getJson("/api/v1/organization/import-jobs/{$jobId}", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.status', 'validated');
        $this->withToken($approver)
            ->getJson("/api/v1/organization/import-jobs/{$jobId}/rows", $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'items');
        $this->withToken($approver)
            ->postJson("/api/v1/organization/import-jobs/{$jobId}/approve", [], $this->actionHeaders('"2"', 'import-approve'))
            ->assertOk()
            ->assertHeader('ETag', '"3"')
            ->assertJsonPath('data.status', 'approved');
        $applied = $this->withToken($submitter)
            ->postJson("/api/v1/organization/import-jobs/{$jobId}/apply", [], $this->actionHeaders('"3"', 'import-apply'))
            ->assertOk()
            ->assertHeader('ETag', '"4"')
            ->assertJsonPath('data.status', 'applied');
        $this->assertSame($jobId, $applied->json('data.id'));
        $this->withToken($submitter)
            ->postJson("/api/v1/organization/import-jobs/{$jobId}/apply", [], $this->actionHeaders('"3"', 'import-apply'))
            ->assertOk()
            ->assertHeader('ETag', '"4"');

        $personId = DB::table('people')->where('employee_number', 'EMP-IMPORT-APPLY')->value('id');
        $this->assertIsString($personId);
        $this->assertDatabaseHas('assignments', ['person_id' => $personId, 'position_id' => $positionId]);
        $this->assertSame(1, DB::table('outbox_events')
            ->where('event_type', 'com.cluster.organization.identityprovisioningrequested.v1')
            ->where('aggregate_id', $personId)->count());
        $this->assertDatabaseHas('import_rows', ['import_job_id' => $jobId, 'decision' => 'accepted']);
    }

    public function test_critical_validation_failure_is_terminal_and_fail_closed(): void
    {
        $token = $this->loginToken();
        $this->bindSource([['employee_number' => 'EMP-MISSING-FIELDS']]);
        $jobId = $this->submit($token, 'import-critical-submit');

        $this->withToken($token)
            ->postJson("/api/v1/organization/import-jobs/{$jobId}/validate", [], $this->actionHeaders('"1"', 'import-critical-validate'))
            ->assertOk()
            ->assertHeader('ETag', '"2"')
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error_rows', 1);
        $this->withToken($token)
            ->postJson("/api/v1/organization/import-jobs/{$jobId}/approve", [], $this->actionHeaders('"2"', 'import-critical-approve'))
            ->assertConflict();
        $this->assertDatabaseCount('people', 0);
        $this->assertDatabaseCount('assignments', 0);
        $this->assertDatabaseHas('outbox_events', [
            'aggregate_id' => $jobId,
            'event_type' => 'com.cluster.organization.importjobfailed.v1',
        ]);
    }

    public function test_validated_import_can_be_rejected_or_cancelled_without_applying_rows(): void
    {
        $submitter = $this->loginToken();
        $approver = $this->loginToken('fixture-account-b', 'fixture-password-b');
        $positionId = $this->positionReference($submitter);
        $this->bindSource([$this->validRow($positionId, 'EMP-IMPORT-TERMINAL')]);

        $rejectedId = $this->submit($submitter, 'import-reject-submit');
        $this->withToken($submitter)
            ->postJson("/api/v1/organization/import-jobs/{$rejectedId}/validate", [], $this->actionHeaders('"1"', 'import-reject-validate'))
            ->assertOk();
        $this->withToken($approver)
            ->postJson("/api/v1/organization/import-jobs/{$rejectedId}/reject", ['reason' => 'بيانات غير معتمدة'], $this->actionHeaders('"2"', 'import-reject'))
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $cancelledId = $this->submit($submitter, 'import-cancel-submit');
        $this->withToken($submitter)
            ->postJson("/api/v1/organization/import-jobs/{$cancelledId}/validate", [], $this->actionHeaders('"1"', 'import-cancel-validate'))
            ->assertOk();
        $this->withToken($approver)
            ->postJson("/api/v1/organization/import-jobs/{$cancelledId}/approve", [], $this->actionHeaders('"2"', 'import-cancel-approve'))
            ->assertOk();
        $this->withToken($submitter)
            ->postJson("/api/v1/organization/import-jobs/{$cancelledId}/cancel", ['reason' => 'ألغي قبل التطبيق'], $this->actionHeaders('"3"', 'import-cancel'))
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseCount('people', 0);
        $this->assertDatabaseCount('assignments', 0);
        $this->assertDatabaseHas('outbox_events', [
            'aggregate_id' => $rejectedId,
            'event_type' => 'com.cluster.organization.importjobrejected.v1',
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'aggregate_id' => $cancelledId,
            'event_type' => 'com.cluster.organization.importjobcancelled.v1',
        ]);
    }

    public function test_validation_rejects_rows_that_downstream_handlers_cannot_accept(): void
    {
        $submitter = $this->loginToken();
        $approver = $this->loginToken('fixture-account-b', 'fixture-password-b');
        $positionId = $this->positionReference($submitter);
        $invalidRow = $this->validRow($positionId, str_repeat('X', 65));
        $this->bindSource([
            $this->validRow($positionId, 'EMP-IMPORT-VALID'),
            [
                ...$invalidRow,
                'display_name_en' => ['not-a-string'],
                'end_at' => $invalidRow['start_at'],
                'is_primary' => 'yes',
            ],
        ]);
        $jobId = $this->submit($submitter, 'import-row-shape-submit');

        $this->withToken($submitter)
            ->postJson("/api/v1/organization/import-jobs/{$jobId}/validate", [], $this->actionHeaders('"1"', 'import-row-shape-validate'))
            ->assertOk()
            ->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.valid_rows', 1)
            ->assertJsonPath('data.error_rows', 1);
        $errors = DB::table('import_rows')->where('import_job_id', $jobId)->where('row_number', 2)->value('validation_errors');
        $this->assertIsString($errors);
        $codes = array_column(json_decode($errors, true, 16, JSON_THROW_ON_ERROR), 'code');
        $this->assertSame(['invalid_employee_number', 'invalid_display_name', 'invalid_is_primary', 'invalid_period'], $codes);

        $this->withToken($approver)
            ->postJson("/api/v1/organization/import-jobs/{$jobId}/approve", [], $this->actionHeaders('"2"', 'import-row-shape-approve'))
            ->assertOk();
        $this->withToken($submitter)
            ->postJson("/api/v1/organization/import-jobs/{$jobId}/apply", [], $this->actionHeaders('"3"', 'import-row-shape-apply'))
            ->assertOk()
            ->assertJsonPath('data.status', 'applied');

        $this->assertDatabaseHas('people', ['employee_number' => 'EMP-IMPORT-VALID']);
        $this->assertDatabaseCount('people', 1);
        $this->assertDatabaseCount('assignments', 1);
    }

    /** @param list<array<string, mixed>> $rows */
    private function bindSource(array $rows): object
    {
        $source = new class($rows) implements ResolveQuarantinedImport
        {
            public int $calls = 0;

            /** @param list<array<string, mixed>> $rows */
            public function __construct(private readonly array $rows) {}

            public function resolve(string $quarantineObjectId, string $templateCode, string $format): ?array
            {
                $this->calls++;

                return $quarantineObjectId === OrganizationImportHttpAdapterTest::QUARANTINE_ID
                    ? ['source_filename' => 'people-assignments.csv', 'rows' => $this->rows]
                    : null;
            }
        };
        $this->app->instance(ResolveQuarantinedImport::class, $source);

        return $source;
    }

    /** @return array<string, mixed> */
    private function validRow(string $positionId, string $employeeNumber): array
    {
        return [
            'employee_number' => $employeeNumber,
            'display_name_ar' => 'موظف مستورد',
            'status' => 'active',
            'position_id' => $positionId,
            'start_at' => now('UTC')->subHour()->format('Y-m-d\TH:i:s.v\Z'),
            'end_at' => now('UTC')->addDay()->format('Y-m-d\TH:i:s.v\Z'),
            'is_primary' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function submitBody(): array
    {
        return [
            'quarantine_object_id' => self::QUARANTINE_ID,
            'template_code' => 'people_assignments',
            'import_type' => 'csv',
            'notes' => 'استيراد محكوم',
        ];
    }

    private function submit(string $token, string $key): string
    {
        return (string) $this->withToken($token)
            ->postJson('/api/v1/organization/import-jobs', $this->submitBody(), $this->writeHeaders($key))
            ->assertStatus(202)->json('data.id');
    }

    private function positionReference(string $token): string
    {
        $clusterId = (string) $this->withToken($token)->postJson('/api/v1/organization/cluster', [
            'code' => 'THC3',
            'name' => 'التجمع الصحي الثالث',
        ], $this->writeHeaders('import-cluster'))->assertCreated()->json('data.id');
        $unitId = (string) $this->withToken($token)->postJson('/api/v1/organization/units', [
            'cluster_id' => $clusterId,
            'type_code' => 'department',
            'code' => 'IMPORTS',
            'name' => 'إدارة الاستيراد',
        ], $this->writeHeaders('import-unit'))->assertCreated()->json('data.id');

        return (string) $this->withToken($token)->postJson('/api/v1/organization/positions', [
            'organization_unit_id' => $unitId,
            'code' => 'IMPORT_TARGET',
            'title' => 'منصب الاستيراد',
        ], $this->writeHeaders('import-position'))->assertCreated()->json('data.id');
    }

    private function loginToken(string $username = 'fixture-account-a', string $password = 'fixture-password-a'): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'username' => $username,
            'password' => $password,
        ], $this->headers())->assertOk()->json('data.access_token');
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-Correlation-ID' => self::CORRELATION_ID];
    }

    /** @return array<string, string> */
    private function writeHeaders(string $key): array
    {
        return [...$this->headers(), 'Idempotency-Key' => $key];
    }

    /** @return array<string, string> */
    private function actionHeaders(string $etag, string $key): array
    {
        return [...$this->writeHeaders($key), 'If-Match' => $etag];
    }
}
