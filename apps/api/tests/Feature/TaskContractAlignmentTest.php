<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\DevelopmentJourneyAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TaskContractAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private const C = '018f6f7d-0c00-7000-8000-000000000201';

    public function test_task_create_and_patch_match_the_openapi_contract(): void
    {
        $this->seed(DevelopmentJourneyAuthorizationSeeder::class);
        $headers = ['X-Correlation-ID' => self::C];
        $login = $this->postJson('/api/v1/auth/login', [
            'username' => 'fixture-account-a',
            'password' => 'fixture-password-a',
        ], $headers)->assertOk();
        $token = $login->json('data.access_token');
        $principalId = $login->json('data.principal.user_id');
        $facilityId = $login->json('data.principal.facility_id');

        $response = $this->withToken($token)->postJson('/api/v1/tasks', [
            'title' => 'Contract aligned task',
            'description' => 'Created through the documented task contract.',
            'owner_organization_unit_id' => $facilityId,
            'assignee_user_id' => $principalId,
            'priority' => 'high',
            'classification' => 'internal',
        ], [...$headers, 'Idempotency-Key' => 'task-contract-create'])->assertCreated();

        $taskId = $response->json('data.id');
        $response->assertJsonPath('data.title', 'Contract aligned task');
        $response->assertJsonPath('data.priority', 'high');
        $response->assertJsonPath('data.classification', 'internal');

        $this->assertDatabaseHas('tasks', [
            'id' => $taskId,
            'title' => 'Contract aligned task',
            'priority' => 'high',
            'classification' => 'internal',
        ]);

        $patched = $this->withToken($token)->patchJson('/api/v1/tasks/'.$taskId, [
            'title' => 'Patched task title',
        ], [...$headers, 'If-Match' => '"1"'])->assertOk();

        $patched->assertJsonPath('data.title', 'Patched task title');
        $this->assertSame('Patched task title', DB::table('tasks')->where('id', $taskId)->value('title'));
        $this->assertSame(2, (int) DB::table('tasks')->where('id', $taskId)->value('lock_version'));
    }

    public function test_openapi_describes_standalone_scoped_tasks_and_explicit_denies(): void
    {
        $path = dirname(base_path(), 2).'/docs/contracts/api/openapi.yaml';
        $contract = file_get_contents($path);

        $this->assertNotFalse($contract);

        if (function_exists('yaml_parse_file')) {
            $this->assertIsArray(yaml_parse_file($path));
        }

        $this->assertMatchesRegularExpression(
            '/TaskCreate:\\R(?:(?!^    [A-Za-z][A-Za-z0-9_]*:).)*?^        owner_organization_unit_id:\\R          \\$ref: \'#\\/components\\/schemas\\/UUIDv7\'/ms',
            $contract,
        );
        $this->assertMatchesRegularExpression(
            '/TaskPatch:\\R(?:(?!^    [A-Za-z][A-Za-z0-9_]*:).)*?^        priority:\\R          type: string\\R          enum:\\R          - low\\R          - normal\\R          - high\\R          - urgent/ms',
            $contract,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/TaskPatch:\\R(?:(?!^    [A-Za-z][A-Za-z0-9_]*:).)*?^          - critical/ms',
            $contract,
        );
        $this->assertMatchesRegularExpression(
            '/^  \/tasks:\\R(?:(?!^  \/[^:]+:).)*?^      - name: view\\R        in: query\\R        schema:\\R          type: string\\R          enum: \\[mine, scope\\]\\R          default: mine.*?^      - name: scope_type\\R        in: query\\R        schema:\\R          type: string\\R          enum: \\[cluster, facility, unit\\].*?^      - name: scope_id\\R        in: query\\R        schema:\\R          \\$ref: \'#\\/components\\/schemas\\/UUIDv7\'/ms',
            $contract,
        );
        $this->assertMatchesRegularExpression(
            '/^  \/tasks:\\R(?:(?!^    post:).)*?^      responses:\\R(?:(?!^    post:).)*?^        \'400\':\\R          \\$ref: \'#\\/components\\/responses\\/BadRequest\'/ms',
            $contract,
        );
        $this->assertStringContainsString('summary: Create a standalone task', $contract);
        $this->assertStringNotContainsString('source-linked', $contract);

        preg_match_all(
            '/- name: adminResource\\R        in: path\\R        required: true(?:\\R        [^\\n]+)*\\R        schema:\\R          type: string\\R          enum:\\R(?<resources>(?:          - [^\\n]+\\R)+)/m',
            $contract,
            $adminResourceEnums,
        );

        $this->assertCount(5, $adminResourceEnums['resources']);

        foreach ($adminResourceEnums['resources'] as $resources) {
            $this->assertMatchesRegularExpression('/^          - explicit-denies$/m', $resources);
        }
    }
}
