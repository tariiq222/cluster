<?php

namespace Modules\Organization\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class JobTitleHttpAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '0197f0e0-0000-7000-8000-000000000601';

    private const ACTIVE_FIXTURE_ID = '0197f0e0-0000-7000-8000-000000000301';

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_creates_and_reads_and_lists_a_job_title(): void
    {
        $token = $this->loginToken();
        $body = $this->payload();

        $created = $this->withToken($token)
            ->postJson('/api/v1/organization/job-titles', $body, $this->writeHeaders('job-title-create'))
            ->assertCreated()
            ->assertHeader('ETag', '"1"')
            ->assertJsonPath('data.code', $body['code'])
            ->assertJsonPath('data.title_ar', $body['title_ar'])
            ->assertJsonPath('data.status', 'active');
        $jobTitleId = $created->json('data.id');
        $this->assertIsString($jobTitleId);

        $replayed = $this->withToken($token)
            ->postJson('/api/v1/organization/job-titles', $body, $this->writeHeaders('job-title-create'))
            ->assertCreated();
        $this->assertSame($jobTitleId, $replayed->json('data.id'));

        $this->withToken($token)
            ->getJson('/api/v1/organization/job-titles?limit=100', $this->headers())
            ->assertOk()
            ->assertJsonFragment(['id' => $jobTitleId, 'code' => $body['code'], 'title_ar' => $body['title_ar'], 'status' => 'active']);
        $this->assertDatabaseCount('job_titles', 1);
        $this->assertDatabaseHas('organization_idempotency_keys', [
            'resource_type' => 'job_title',
            'resource_id' => $jobTitleId,
        ]);
    }

    public function test_rejects_duplicate_code(): void
    {
        $token = $this->loginToken();
        $body = $this->payload();

        $this->withToken($token)
            ->postJson('/api/v1/organization/job-titles', $body, $this->writeHeaders('job-title-create-first'))
            ->assertCreated()
            ->assertJsonPath('data.code', $body['code']);

        $this->withToken($token)
            ->postJson('/api/v1/organization/job-titles', $body, $this->writeHeaders('job-title-create-second'))
            ->assertConflict()
            ->assertJsonPath('type', 'https://cluster.example/problems/job-title-already-exists');
        $this->assertDatabaseCount('job_titles', 1);
    }

    public function test_rejects_invalid_payload(): void
    {
        $token = $this->loginToken();

        $this->withToken($token)
            ->postJson('/api/v1/organization/job-titles', [
                'code' => 'VALIDCODE',
            ], $this->writeHeaders('job-title-missing-title-ar'))
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-job-title');

        $this->withToken($token)
            ->postJson('/api/v1/organization/job-titles', [
                'code' => 'invalid_code',
                'title_ar' => 'عنوان غير صالح',
            ], $this->writeHeaders('job-title-bad-code'))
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-job-title');

        $this->withToken($token)
            ->getJson('/api/v1/organization/job-titles?cursor=this-is-not-a-cursor', $this->headers())
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-pagination');

        $this->assertDatabaseCount('job_titles', 0);
    }

    public function test_filters_by_active_status(): void
    {
        $token = $this->loginToken();

        $created = $this->withToken($token)
            ->postJson('/api/v1/organization/job-titles', $this->payload(), $this->writeHeaders('job-title-active-create'))
            ->assertCreated();
        $activeId = $created->json('data.id');
        $this->assertIsString($activeId);

        DB::table('job_titles')->insert([
            'id' => self::ACTIVE_FIXTURE_ID,
            'code' => 'INACTIVE_JOB_TITLE',
            'title_ar' => 'عنوان غير نشط',
            'status' => 'inactive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertDatabaseCount('job_titles', 2);

        $this->withToken($token)
            ->getJson('/api/v1/organization/job-titles?limit=100', $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $activeId)
            ->assertJsonPath('items.0.status', 'active');
        $inactive = collect($this->withToken($token)
            ->getJson('/api/v1/organization/job-titles?limit=100', $this->headers())
            ->assertOk()->json('items'))
            ->firstWhere('id', self::ACTIVE_FIXTURE_ID);
        $this->assertNull($inactive);
        $this->assertDatabaseHas('job_titles', ['id' => self::ACTIVE_FIXTURE_ID, 'status' => 'inactive']);
    }

    /** @return array<string, string> */
    private function payload(): array
    {
        return [
            'code' => 'JOB_TITLE_001',
            'title_ar' => 'مدير قسم',
        ];
    }

    private function loginToken(): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'username' => 'fixture-account-a',
            'password' => 'fixture-password-a',
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
}
