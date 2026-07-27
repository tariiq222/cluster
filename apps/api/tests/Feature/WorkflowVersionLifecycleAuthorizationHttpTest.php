<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Authentication boundary for the workflow version lifecycle routes.
 *
 * KNOWN GAP (pinned, not endorsed): beyond authentication these routes run
 * no capability check — `definitions()` and `instances()` deny without
 * `workflow.read`/`workflow.manage`, `versions()` and `publish()` do not.
 * The day a capability lands (see the Workflow audit finding on
 * WorkflowController::versions/::publish) these tests gain a 403 sibling.
 */
final class WorkflowVersionLifecycleAuthorizationHttpTest extends TestCase
{
    use RefreshDatabase;

    private const C = '018f6f7d-0c00-7000-8000-000000000303';

    // No session row backs this value; the session middleware must reject it.
    private const BOGUS_TOKEN = '018f6f7d-0c00-7000-8000-0000000003ff';

    public function test_listing_versions_requires_an_authenticated_principal(): void
    {
        $definitionId = $this->definition();

        $this->withToken(self::BOGUS_TOKEN)->getJson('/api/v1/workflow/definitions/'.$definitionId.'/versions', ['X-Correlation-ID' => self::C])
            ->assertUnauthorized();
    }

    public function test_creating_a_version_requires_an_authenticated_principal(): void
    {
        $definitionId = $this->definition();
        $body = ['nodes' => [['key' => 'start', 'type' => 'start'], ['key' => 'review', 'type' => 'work_item'], ['key' => 'end', 'type' => 'end']], 'transitions' => [['from' => 'start', 'to' => 'review'], ['from' => 'review', 'to' => 'end']]];

        $this->withToken(self::BOGUS_TOKEN)->postJson('/api/v1/workflow/definitions/'.$definitionId.'/versions', $body, ['X-Correlation-ID' => self::C, 'Idempotency-Key' => 'authz-version'])
            ->assertUnauthorized();
        // Only the initial draft from definition creation may exist.
        $this->assertDatabaseCount('workflow_versions', 1);
    }

    public function test_publishing_a_version_requires_an_authenticated_principal(): void
    {
        $definitionId = $this->definition();
        $versionId = $this->app['db']->table('workflow_versions')->where('workflow_definition_id', $definitionId)->sole()->id;

        $this->withToken(self::BOGUS_TOKEN)->postJson('/api/v1/workflow/versions/'.$versionId.'/publish', [], ['X-Correlation-ID' => self::C, 'Idempotency-Key' => 'authz-publish'])
            ->assertUnauthorized();
        $this->assertSame('draft', $this->app['db']->table('workflow_versions')->where('id', $versionId)->sole()->definition_state);
    }

    private function definition(): string
    {
        $headers = ['X-Correlation-ID' => self::C];
        $token = $this->postJson('/api/v1/auth/login', ['username' => 'fixture-account-a', 'password' => 'fixture-password-a'], $headers)->assertOk()->json('data.access_token');

        return $this->withToken($token)->postJson('/api/v1/workflow/definitions', ['code' => 'authz-flow-'.bin2hex(random_bytes(3)), 'name' => 'Authz', 'source_record_type' => 'work_record'], [...$headers, 'Idempotency-Key' => 'authz-flow-'.bin2hex(random_bytes(3))])->assertCreated()->json('data.definition.id');
    }
}
