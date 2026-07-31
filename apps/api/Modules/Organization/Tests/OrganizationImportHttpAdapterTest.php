<?php

namespace Modules\Organization\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    public function test_uploaded_csv_becomes_a_real_quarantine_object_and_validates_end_to_end(): void
    {
        Storage::fake('organization-quarantine');
        $token = $this->loginToken();
        $positionId = $this->positionReference($token);
        $startAt = now('UTC')->subHour()->format('Y-m-d\TH:i:s.v\Z');
        $endAt = now('UTC')->addDay()->format('Y-m-d\TH:i:s.v\Z');
        $csv = "employee_number,display_name_ar,status,position_id,start_at,end_at,is_primary\n"
            ."EMP-CSV-001,موظف ملف csv,active,{$positionId},{$startAt},{$endAt},true\n";

        $uploaded = (string) $this->withToken($token)
            ->post('/api/v1/organization/import-files', [
                'file' => UploadedFile::fake()->createWithContent('people-import.csv', $csv),
                'template_code' => 'people_assignments',
                'import_type' => 'csv',
            ], $this->headers())
            ->assertCreated()
            ->assertJsonStructure(['data' => ['quarantine_object_id']])
            ->json('data.quarantine_object_id');
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uploaded);

        $stored = json_decode((string) Storage::disk('organization-quarantine')->get($uploaded.'.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('people-import.csv', $stored['source_filename']);
        $this->assertCount(1, $stored['rows']);
        $this->assertTrue($stored['rows'][0]['is_primary']);
        $this->assertSame('EMP-CSV-001', $stored['rows'][0]['employee_number']);

        $jobId = $this->submit($token, 'import-csv-e2e-submit', 'people_assignments', $uploaded);
        $this->withToken($token)
            ->postJson("/api/v1/organization/import-jobs/{$jobId}/validate", [], $this->actionHeaders('"1"', 'import-csv-e2e-validate'))
            ->assertOk()
            ->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.valid_rows', 1)
            ->assertJsonPath('data.error_rows', 0);
        $this->assertDatabaseHas('import_jobs', ['id' => $jobId, 'source_filename' => 'people-import.csv']);
    }

    public function test_import_file_upload_rejects_invalid_payloads_without_writing_quarantine_objects(): void
    {
        Storage::fake('organization-quarantine');
        $token = $this->loginToken();

        $this->withToken($token)
            ->post('/api/v1/organization/import-files', [
                'file' => UploadedFile::fake()->createWithContent('units.csv', "cluster_id,type_code,code,name\n"),
                'template_code' => 'unknown_template',
                'import_type' => 'csv',
            ], $this->headers())
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-import-file');

        $tooMany = "cluster_id,type_code,code,name\n";
        for ($index = 1; $index <= 1001; $index++) {
            $tooMany .= "018f6f7d-0c00-7000-8000-000000000001,hospital,FAC_{$index},مرفق {$index}\n";
        }
        $this->withToken($token)
            ->post('/api/v1/organization/import-files', [
                'file' => UploadedFile::fake()->createWithContent('too-many.csv', $tooMany),
                'template_code' => 'facilities',
                'import_type' => 'csv',
            ], $this->headers())
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/import-rows-too-many');
        $this->withToken($token)
            ->post('/api/v1/organization/import-files', [
                'file' => UploadedFile::fake()->createWithContent('empty.csv', "cluster_id,type_code,code,name\n"),
                'template_code' => 'facilities',
                'import_type' => 'csv',
            ], $this->headers())
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-import-file');

        $this->assertCount(0, Storage::disk('organization-quarantine')->allFiles());
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

    public function test_submitter_cannot_approve_their_own_import_job(): void
    {
        $submitter = $this->loginToken();
        $positionId = $this->positionReference($submitter);
        $this->bindSource([$this->validRow($positionId, 'EMP-IMPORT-SELF-APPROVE')]);
        $jobId = $this->submit($submitter, 'import-self-approve-submit');

        $this->withToken($submitter)
            ->postJson("/api/v1/organization/import-jobs/{$jobId}/validate", [], $this->actionHeaders('"1"', 'import-self-approve-validate'))
            ->assertOk()
            ->assertJsonPath('data.status', 'validated');

        // The dual-approval guard is enforced at the approve transition only;
        // the submitter approving their own job must be rejected with the
        // mapped 409 problem type (controller: import_dual_approval_required).
        $this->withToken($submitter)
            ->postJson("/api/v1/organization/import-jobs/{$jobId}/approve", [], $this->actionHeaders('"2"', 'import-self-approve'))
            ->assertStatus(409)
            ->assertJsonPath('type', 'https://cluster.example/problems/import-dual-approval-required');

        // The job must remain validated, no approval side-effects must leak.
        $this->assertDatabaseHas('import_jobs', ['id' => $jobId, 'status' => 'validated', 'approved_by_user_id' => null]);
        $this->assertDatabaseMissing('outbox_events', [
            'aggregate_id' => $jobId,
            'event_type' => 'com.cluster.organization.importjobapproved.v1',
        ]);
        $this->assertDatabaseCount('people', 0);
        $this->assertDatabaseCount('assignments', 0);
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

    public function test_facility_import_template_validates_and_applies_create_rows(): void
    {
        $submitter = $this->loginToken();
        $approver = $this->loginToken('fixture-account-b', 'fixture-password-b');
        $clusterId = $this->clusterReference($submitter, 'import-facility-cluster');
        $this->bindSource([
            [
                'cluster_id' => $clusterId,
                'type_code' => 'hospital',
                'code' => 'FAC_IMPORT_001',
                'name' => 'مرفق مستورد',
                'name_en' => 'Imported Facility',
            ],
            [
                'cluster_id' => $clusterId,
                'type_code' => 'unknown_type',
                'code' => 'FAC_IMPORT_002',
                'name' => 'مرفق مرفوض',
            ],
        ]);
        $jobId = $this->submit($submitter, 'import-facility-submit', 'facilities');

        $this->withToken($submitter)
            ->postJson("/api/v1/organization/import-jobs/{$jobId}/validate", [], $this->actionHeaders('"1"', 'import-facility-validate'))
            ->assertOk()
            ->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.valid_rows', 1)
            ->assertJsonPath('data.error_rows', 1);
        $this->withToken($approver)
            ->postJson("/api/v1/organization/import-jobs/{$jobId}/approve", [], $this->actionHeaders('"2"', 'import-facility-approve'))
            ->assertOk();
        $this->withToken($submitter)
            ->postJson("/api/v1/organization/import-jobs/{$jobId}/apply", [], $this->actionHeaders('"3"', 'import-facility-apply'))
            ->assertOk()
            ->assertJsonPath('data.status', 'applied');

        $facilityId = DB::table('facilities')->where('code', 'FAC_IMPORT_001')->value('id');
        $this->assertIsString($facilityId);
        $this->assertDatabaseMissing('facilities', ['code' => 'FAC_IMPORT_002']);
        $this->assertDatabaseHas('outbox_events', [
            'aggregate_id' => $facilityId,
            'event_type' => 'com.cluster.organization.facilitycreated.v1',
        ]);
        $this->assertDatabaseHas('import_rows', [
            'import_job_id' => $jobId,
            'row_number' => 1,
            'proposed_target_id' => $facilityId,
        ]);
    }

    public function test_facility_import_validation_fails_on_duplicate_codes_before_apply(): void
    {
        $submitter = $this->loginToken();
        $clusterId = $this->clusterReference($submitter, 'import-facility-duplicate-cluster');
        $this->bindSource([
            ['cluster_id' => $clusterId, 'type_code' => 'hospital', 'code' => 'FAC_IMPORT_DUP', 'name' => 'مرفق مكرر ١'],
            ['cluster_id' => $clusterId, 'type_code' => 'hospital', 'code' => 'FAC_IMPORT_DUP', 'name' => 'مرفق مكرر ٢'],
        ]);
        $jobId = $this->submit($submitter, 'import-facility-duplicate-submit', 'facilities');

        $this->withToken($submitter)
            ->postJson("/api/v1/organization/import-jobs/{$jobId}/validate", [], $this->actionHeaders('"1"', 'import-facility-duplicate-validate'))
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.valid_rows', 0)
            ->assertJsonPath('data.error_rows', 2);
        $this->assertRowHasValidationCode($jobId, 1, 'duplicate_facility_code_in_import');
        $this->assertRowHasValidationCode($jobId, 2, 'duplicate_facility_code_in_import');
        $this->assertImportApplyNotReached($submitter, $jobId, 'facility-duplicate');
        $this->assertDatabaseMissing('facilities', ['code' => 'FAC_IMPORT_DUP']);
    }

    public function test_invalid_duplicate_row_does_not_poison_valid_facility_import_row(): void
    {
        $submitter = $this->loginToken();
        $approver = $this->loginToken('fixture-account-b', 'fixture-password-b');
        $clusterId = $this->clusterReference($submitter, 'import-facility-invalid-duplicate-cluster');
        $this->bindSource([
            ['cluster_id' => $clusterId, 'type_code' => 'unknown_type', 'code' => 'FAC_IMPORT_PARTIAL', 'name' => 'مرفق غير صالح'],
            ['cluster_id' => $clusterId, 'type_code' => 'hospital', 'code' => 'FAC_IMPORT_PARTIAL', 'name' => 'مرفق صالح'],
        ]);
        $jobId = $this->submit($submitter, 'import-facility-invalid-duplicate-submit', 'facilities');

        $this->withToken($submitter)
            ->postJson("/api/v1/organization/import-jobs/{$jobId}/validate", [], $this->actionHeaders('"1"', 'import-facility-invalid-duplicate-validate'))
            ->assertOk()
            ->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.valid_rows', 1)
            ->assertJsonPath('data.error_rows', 1);
        $this->assertRowHasValidationCode($jobId, 1, 'invalid_type');
        $this->assertRowLacksValidationCode($jobId, 2, 'duplicate_facility_code_in_import');

        $this->withToken($approver)
            ->postJson("/api/v1/organization/import-jobs/{$jobId}/approve", [], $this->actionHeaders('"2"', 'import-facility-invalid-duplicate-approve'))
            ->assertOk();
        $this->withToken($submitter)
            ->postJson("/api/v1/organization/import-jobs/{$jobId}/apply", [], $this->actionHeaders('"3"', 'import-facility-invalid-duplicate-apply'))
            ->assertOk()
            ->assertJsonPath('data.status', 'applied');

        $this->assertDatabaseHas('facilities', ['code' => 'FAC_IMPORT_PARTIAL', 'name_ar' => 'مرفق صالح']);
        $this->assertSame(1, DB::table('facilities')->where('code', 'FAC_IMPORT_PARTIAL')->count());
    }

    public function test_unit_import_template_validates_and_applies_create_rows(): void
    {
        $submitter = $this->loginToken();
        $approver = $this->loginToken('fixture-account-b', 'fixture-password-b');
        $clusterId = $this->clusterReference($submitter, 'import-tree-cluster');
        $this->bindSource([
            [
                'cluster_id' => $clusterId,
                'type_code' => 'department',
                'code' => 'UNIT_IMPORT_001',
                'name' => 'وحدة مستوردة',
            ],
        ]);
        $unitJobId = $this->submit($submitter, 'import-unit-submit', 'organization_units');

        $this->withToken($submitter)
            ->postJson("/api/v1/organization/import-jobs/{$unitJobId}/validate", [], $this->actionHeaders('"1"', 'import-unit-validate'))
            ->assertOk()
            ->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.valid_rows', 1);
        $this->withToken($approver)
            ->postJson("/api/v1/organization/import-jobs/{$unitJobId}/approve", [], $this->actionHeaders('"2"', 'import-unit-approve'))
            ->assertOk();
        $this->withToken($submitter)
            ->postJson("/api/v1/organization/import-jobs/{$unitJobId}/apply", [], $this->actionHeaders('"3"', 'import-unit-apply'))
            ->assertOk();
        $unitId = DB::table('organization_units')->where('code', 'UNIT_IMPORT_001')->value('id');
        $this->assertIsString($unitId);
        $this->assertDatabaseHas('outbox_events', [
            'aggregate_id' => $unitId,
            'event_type' => 'com.cluster.organization.organizationunitcreated.v1',
        ]);
    }

    public function test_unit_import_validation_fails_on_duplicate_codes_under_parent_before_apply(): void
    {
        $submitter = $this->loginToken();
        $clusterId = $this->clusterReference($submitter, 'import-unit-duplicate-cluster');
        $this->bindSource([
            ['cluster_id' => $clusterId, 'type_code' => 'department', 'code' => 'UNIT_IMPORT_DUP', 'name' => 'وحدة مكررة ١'],
            ['cluster_id' => $clusterId, 'parent_id' => $clusterId, 'type_code' => 'department', 'code' => 'UNIT_IMPORT_DUP', 'name' => 'وحدة مكررة ٢'],
        ]);
        $jobId = $this->submit($submitter, 'import-unit-duplicate-submit', 'organization_units');

        $this->withToken($submitter)
            ->postJson("/api/v1/organization/import-jobs/{$jobId}/validate", [], $this->actionHeaders('"1"', 'import-unit-duplicate-validate'))
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.valid_rows', 0)
            ->assertJsonPath('data.error_rows', 2);
        $this->assertRowHasValidationCode($jobId, 1, 'duplicate_organization_unit_code_in_import');
        $this->assertRowHasValidationCode($jobId, 2, 'duplicate_organization_unit_code_in_import');
        $this->assertImportApplyNotReached($submitter, $jobId, 'unit-duplicate');
        $this->assertDatabaseMissing('organization_units', ['code' => 'UNIT_IMPORT_DUP']);
    }

    public function test_position_import_template_validates_and_applies_create_rows(): void
    {
        $submitter = $this->loginToken();
        $approver = $this->loginToken('fixture-account-b', 'fixture-password-b');
        $unitId = $this->unitReference($submitter, 'import-position-tree');

        $this->bindSource([
            [
                'organization_unit_id' => $unitId,
                'code' => 'POS_IMPORT_001',
                'title' => 'منصب مستورد',
            ],
            [
                'organization_unit_id' => $unitId,
                'code' => 'bad-code',
                'title' => 'منصب مرفوض',
            ],
        ]);
        $positionJobId = $this->submit($submitter, 'import-position-submit', 'positions');
        $this->withToken($submitter)
            ->postJson("/api/v1/organization/import-jobs/{$positionJobId}/validate", [], $this->actionHeaders('"1"', 'import-position-validate'))
            ->assertOk()
            ->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.valid_rows', 1)
            ->assertJsonPath('data.error_rows', 1);
        $this->withToken($approver)
            ->postJson("/api/v1/organization/import-jobs/{$positionJobId}/approve", [], $this->actionHeaders('"2"', 'import-position-approve'))
            ->assertOk();
        $this->withToken($submitter)
            ->postJson("/api/v1/organization/import-jobs/{$positionJobId}/apply", [], $this->actionHeaders('"3"', 'import-position-apply'))
            ->assertOk()
            ->assertJsonPath('data.status', 'applied');

        $positionId = DB::table('positions')->where('code', 'POS_IMPORT_001')->value('id');
        $this->assertIsString($positionId);
        $this->assertDatabaseMissing('positions', ['code' => 'bad-code']);
        $this->assertDatabaseHas('outbox_events', [
            'aggregate_id' => $positionId,
            'event_type' => 'com.cluster.organization.positioncreated.v1',
        ]);
    }

    public function test_position_import_validation_fails_on_duplicate_codes_in_unit_before_apply(): void
    {
        $submitter = $this->loginToken();
        $unitId = $this->unitReference($submitter, 'import-position-duplicate-tree');
        $this->bindSource([
            ['organization_unit_id' => $unitId, 'code' => 'POS_IMPORT_DUP', 'title' => 'منصب مكرر ١'],
            ['organization_unit_id' => $unitId, 'code' => 'POS_IMPORT_DUP', 'title' => 'منصب مكرر ٢'],
        ]);
        $jobId = $this->submit($submitter, 'import-position-duplicate-submit', 'positions');

        $this->withToken($submitter)
            ->postJson("/api/v1/organization/import-jobs/{$jobId}/validate", [], $this->actionHeaders('"1"', 'import-position-duplicate-validate'))
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.valid_rows', 0)
            ->assertJsonPath('data.error_rows', 2);
        $this->assertRowHasValidationCode($jobId, 1, 'duplicate_position_code_in_import');
        $this->assertRowHasValidationCode($jobId, 2, 'duplicate_position_code_in_import');
        $this->assertImportApplyNotReached($submitter, $jobId, 'position-duplicate');
        $this->assertDatabaseMissing('positions', ['code' => 'POS_IMPORT_DUP']);
    }

    public function test_people_assignment_import_validation_fails_on_unappliable_status_and_overlaps_before_apply(): void
    {
        $submitter = $this->loginToken();
        $unitId = $this->unitReference($submitter, 'import-assignment-overlap-tree');
        $existingPositionId = $this->createPosition($submitter, $unitId, 'IMPORT_EXISTING', 'منصب مشغول');
        $primaryPositionId = $this->createPosition($submitter, $unitId, 'IMPORT_PRIMARY', 'منصب رئيسي');
        $batchPositionId = $this->createPosition($submitter, $unitId, 'IMPORT_BATCH', 'منصب تداخل ملف');
        $batchPrimaryAId = $this->createPosition($submitter, $unitId, 'IMPORT_PRIMARY_A', 'منصب رئيسي أ');
        $batchPrimaryBId = $this->createPosition($submitter, $unitId, 'IMPORT_PRIMARY_B', 'منصب رئيسي ب');
        $existingPersonId = $this->createPerson($submitter, 'EMP-IMPORT-EXISTING', 'import-existing-person');
        $this->createAssignment($submitter, $existingPersonId, $existingPositionId, 'import-existing-assignment');
        $this->bindSource([
            [...$this->validRow($primaryPositionId, 'EMP-IMPORT-SUSPENDED'), 'status' => 'suspended'],
            $this->validRow($existingPositionId, 'EMP-IMPORT-POSITION-OVERLAP'),
            $this->validRow($primaryPositionId, 'EMP-IMPORT-EXISTING'),
            $this->validRow($batchPositionId, 'EMP-IMPORT-BATCH-A'),
            $this->validRow($batchPositionId, 'EMP-IMPORT-BATCH-B'),
            $this->validRow($batchPrimaryAId, 'EMP-IMPORT-BATCH-PRIMARY'),
            $this->validRow($batchPrimaryBId, 'EMP-IMPORT-BATCH-PRIMARY'),
        ]);
        $jobId = $this->submit($submitter, 'import-assignment-overlap-submit');

        $this->withToken($submitter)
            ->postJson("/api/v1/organization/import-jobs/{$jobId}/validate", [], $this->actionHeaders('"1"', 'import-assignment-overlap-validate'))
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.valid_rows', 0)
            ->assertJsonPath('data.error_rows', 7);
        $this->assertRowHasValidationCode($jobId, 1, 'person_not_assignable');
        $this->assertRowHasValidationCode($jobId, 2, 'position_assignment_overlap');
        $this->assertRowHasValidationCode($jobId, 3, 'primary_assignment_overlap');
        $this->assertRowHasValidationCode($jobId, 4, 'position_assignment_overlap');
        $this->assertRowHasValidationCode($jobId, 5, 'position_assignment_overlap');
        $this->assertRowHasValidationCode($jobId, 6, 'primary_assignment_overlap');
        $this->assertRowHasValidationCode($jobId, 7, 'primary_assignment_overlap');
        $this->assertImportApplyNotReached($submitter, $jobId, 'assignment-overlap');
        $this->assertDatabaseMissing('people', ['employee_number' => 'EMP-IMPORT-SUSPENDED']);
        $this->assertDatabaseMissing('people', ['employee_number' => 'EMP-IMPORT-BATCH-A']);
        $this->assertDatabaseCount('assignments', 1);
    }

    public function test_people_assignment_validation_rejects_position_when_unit_is_inactive_without_poisoning_valid_row(): void
    {
        $submitter = $this->loginToken();
        $approver = $this->loginToken('fixture-account-b', 'fixture-password-b');
        $clusterId = $this->clusterReference($submitter, 'import-inactive-unit-cluster');
        $inactiveUnitId = $this->createUnit($submitter, $clusterId, 'import-inactive-unit', 'INACTIVE_IMPORTS');
        $activeUnitId = $this->createUnit($submitter, $clusterId, 'import-active-unit', 'ACTIVE_IMPORTS');
        $inactivePositionId = $this->createPosition($submitter, $inactiveUnitId, 'INACTIVE_UNIT_POS', 'منصب وحدة غير فعالة');
        $activePositionId = $this->createPosition($submitter, $activeUnitId, 'ACTIVE_UNIT_POS', 'منصب وحدة فعالة');
        $this->withToken($submitter)
            ->patchJson("/api/v1/organization/units/{$inactiveUnitId}", [
                'status' => 'inactive',
                'reason' => 'تعطيل لاختبار الاستيراد',
            ], $this->patchHeaders('"1"', 'import-inactive-unit-patch'))
            ->assertOk();
        $this->bindSource([
            $this->validRow($inactivePositionId, 'EMP-IMPORT-INACTIVE-UNIT'),
            $this->validRow($activePositionId, 'EMP-IMPORT-ACTIVE-UNIT'),
        ]);
        $jobId = $this->submit($submitter, 'import-inactive-unit-submit');

        $this->withToken($submitter)
            ->postJson("/api/v1/organization/import-jobs/{$jobId}/validate", [], $this->actionHeaders('"1"', 'import-inactive-unit-validate'))
            ->assertOk()
            ->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.valid_rows', 1)
            ->assertJsonPath('data.error_rows', 1);
        $this->assertRowHasValidationCode($jobId, 1, 'invalid_position');

        $this->withToken($approver)
            ->postJson("/api/v1/organization/import-jobs/{$jobId}/approve", [], $this->actionHeaders('"2"', 'import-inactive-unit-approve'))
            ->assertOk();
        $this->withToken($submitter)
            ->postJson("/api/v1/organization/import-jobs/{$jobId}/apply", [], $this->actionHeaders('"3"', 'import-inactive-unit-apply'))
            ->assertOk()
            ->assertJsonPath('data.status', 'applied');

        $this->assertDatabaseMissing('people', ['employee_number' => 'EMP-IMPORT-INACTIVE-UNIT']);
        $activePersonId = DB::table('people')->where('employee_number', 'EMP-IMPORT-ACTIVE-UNIT')->value('id');
        $this->assertIsString($activePersonId);
        $this->assertDatabaseHas('assignments', ['person_id' => $activePersonId, 'position_id' => $activePositionId]);
        $this->assertDatabaseMissing('assignments', ['position_id' => $inactivePositionId]);
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
    private function submitBody(string $templateCode = 'people_assignments', string $quarantineObjectId = self::QUARANTINE_ID): array
    {
        return [
            'quarantine_object_id' => $quarantineObjectId,
            'template_code' => $templateCode,
            'import_type' => 'csv',
            'notes' => 'استيراد محكوم',
        ];
    }

    private function submit(string $token, string $key, string $templateCode = 'people_assignments', string $quarantineObjectId = self::QUARANTINE_ID): string
    {
        return (string) $this->withToken($token)
            ->postJson('/api/v1/organization/import-jobs', $this->submitBody($templateCode, $quarantineObjectId), $this->writeHeaders($key))
            ->assertStatus(202)->json('data.id');
    }

    private function clusterReference(string $token, string $key): string
    {
        return (string) $this->withToken($token)->postJson('/api/v1/organization/cluster', [
            'code' => 'THC3',
            'name' => 'التجمع الصحي الثالث',
        ], $this->writeHeaders($key))->assertCreated()->json('data.id');
    }

    private function unitReference(string $token, string $key): string
    {
        $clusterId = $this->clusterReference($token, $key.'-cluster');

        return $this->createUnit($token, $clusterId, $key.'-unit', 'IMPORTS');
    }

    private function createUnit(string $token, string $clusterId, string $key, string $code): string
    {
        return (string) $this->withToken($token)->postJson('/api/v1/organization/units', [
            'cluster_id' => $clusterId,
            'type_code' => 'department',
            'code' => $code,
            'name' => 'إدارة الاستيراد',
        ], $this->writeHeaders($key))->assertCreated()->json('data.id');
    }

    private function createPosition(string $token, string $unitId, string $code, string $title): string
    {
        return (string) $this->withToken($token)->postJson('/api/v1/organization/positions', [
            'organization_unit_id' => $unitId,
            'code' => $code,
            'title' => $title,
        ], $this->writeHeaders('import-position-'.$code))->assertCreated()->json('data.id');
    }

    private function createPerson(string $token, string $employeeNumber, string $key): string
    {
        return (string) $this->withToken($token)->postJson('/api/v1/organization/people', [
            'employee_number' => $employeeNumber,
            'display_name_ar' => 'موظف استيراد',
            'status' => 'active',
        ], $this->writeHeaders($key))->assertCreated()->json('data.id');
    }

    private function createAssignment(string $token, string $personId, string $positionId, string $key): void
    {
        $this->withToken($token)->postJson('/api/v1/organization/assignments', [
            'person_id' => $personId,
            'position_id' => $positionId,
            'start_at' => now('UTC')->subHour()->format('Y-m-d\TH:i:s.v\Z'),
            'end_at' => now('UTC')->addDay()->format('Y-m-d\TH:i:s.v\Z'),
            'is_primary' => true,
        ], $this->writeHeaders($key))->assertCreated();
    }

    private function assertImportApplyNotReached(string $token, string $jobId, string $key): void
    {
        $this->withToken($token)
            ->postJson("/api/v1/organization/import-jobs/{$jobId}/approve", [], $this->actionHeaders('"2"', 'import-'.$key.'-approve'))
            ->assertConflict();
        $this->withToken($token)
            ->postJson("/api/v1/organization/import-jobs/{$jobId}/apply", [], $this->actionHeaders('"2"', 'import-'.$key.'-apply'))
            ->assertConflict();
        $this->assertDatabaseMissing('outbox_events', [
            'aggregate_id' => $jobId,
            'event_type' => 'com.cluster.organization.importjobapplied.v1',
        ]);
    }

    private function assertRowHasValidationCode(string $jobId, int $rowNumber, string $code): void
    {
        $errors = DB::table('import_rows')
            ->where('import_job_id', $jobId)
            ->where('row_number', $rowNumber)
            ->value('validation_errors');
        $this->assertIsString($errors);
        $this->assertContains($code, array_column(json_decode($errors, true, 16, JSON_THROW_ON_ERROR), 'code'));
    }

    private function assertRowLacksValidationCode(string $jobId, int $rowNumber, string $code): void
    {
        $errors = DB::table('import_rows')
            ->where('import_job_id', $jobId)
            ->where('row_number', $rowNumber)
            ->value('validation_errors');
        if ($errors === null) {
            return;
        }

        $this->assertIsString($errors);
        $this->assertNotContains($code, array_column(json_decode($errors, true, 16, JSON_THROW_ON_ERROR), 'code'));
    }

    private function positionReference(string $token): string
    {
        $unitId = $this->unitReference($token, 'import');

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

    /** @return array<string, string> */
    private function patchHeaders(string $etag, string $key): array
    {
        return [...$this->actionHeaders($etag, $key), 'Content-Type' => 'application/merge-patch+json'];
    }
}
