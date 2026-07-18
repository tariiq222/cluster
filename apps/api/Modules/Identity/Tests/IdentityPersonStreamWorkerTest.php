<?php

namespace Modules\Identity\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Modules\Identity\Features\ConsumeOrganizationPersonEvents\Handler\ConsumeOrganizationPersonEventHandler;
use Modules\Identity\Features\ConsumeOrganizationPersonEvents\Worker\IdentityPersonStreamWorker;
use Shared\Infrastructure\Streams\RedisStreamTransport;
use Tests\Support\Streams\InMemoryRedisStreamTransport;
use Tests\TestCase;

class IdentityPersonStreamWorkerTest extends TestCase
{
    use RefreshDatabase;

    public function test_committed_provisioning_event_relays_to_identity_inbox_once(): void
    {
        $token = (string) $this->postJson('/api/v1/auth/login', [
            'username' => 'fixture-account-a',
            'password' => 'fixture-password-a',
        ], $this->headers())->assertOk()->json('data.access_token');
        $personResponse = $this->withToken($token)->postJson('/api/v1/organization/people', [
            'employee_number' => 'EMP-STREAM-001',
            'display_name_ar' => 'موظف المسار',
            'status' => 'active',
        ], [...$this->headers(), 'Idempotency-Key' => 'stream-person'])->assertCreated();
        $personId = (string) $personResponse->json('data.id');

        $transport = new InMemoryRedisStreamTransport;
        $this->app->instance(RedisStreamTransport::class, $transport);

        $this->artisan('organization:relay-person-events --once')->assertSuccessful();
        $this->artisan('identity:consume-person-events --once --consumer=identity-worker')->assertSuccessful();
        $this->artisan('identity:consume-person-events --once --consumer=identity-worker')->assertSuccessful();
        $this->assertDatabaseHas('identity_person_provisioning', [
            'person_id' => $personId,
            'person_version' => 1,
            'requested_account_status' => 'pending',
        ]);
        $this->assertDatabaseCount('identity_inbox', 1);

        $this->withToken($token)->patchJson('/api/v1/organization/people/'.$personId, [
            'status' => 'suspended',
        ], [
            ...$this->headers(),
            'If-Match' => '"1"',
            'Content-Type' => 'application/merge-patch+json',
        ])->assertOk();

        $this->artisan('organization:relay-person-events --once')->assertSuccessful();
        $this->artisan('identity:consume-person-events --once --consumer=identity-worker')->assertSuccessful();
        $this->assertDatabaseHas('identity_person_event_watermarks', [
            'person_id' => $personId,
            'last_person_version' => 2,
            'last_event_type' => 'com.cluster.organization.personaccessstatuschanged.v1',
        ]);
        $this->assertDatabaseCount('identity_inbox', 3);
        $this->assertSame(0, DB::table('outbox_events')->whereIn('event_type', [
            'com.cluster.organization.identityprovisioningrequested.v1',
            'com.cluster.organization.personaccessstatuschanged.v1',
            'com.cluster.organization.personregistered.v1',
            'com.cluster.organization.personupdated.v1',
        ])->whereNull('published_at')->count());
    }

    public function test_commands_require_bounded_mode_and_valid_consumer(): void
    {
        $this->artisan('organization:relay-person-events')->assertFailed();
        $this->artisan('identity:consume-person-events --once')->assertFailed();
        $this->artisan('identity:consume-person-events --once --consumer=bad/name')->assertFailed();
    }

    public function test_worker_shares_the_batch_and_names_dlq_sources_by_stream(): void
    {
        $provisioning = 'platform.organization.identity-provisioning-requested.v1';
        $status = 'platform.organization.person-access-status-changed.v1';
        $updated = 'platform.organization.person-updated.v1';
        $transport = Mockery::mock(RedisStreamTransport::class);
        $transport->shouldReceive('createGroup')->once()->with($provisioning, 'identity.organization-person-events.v1');
        $transport->shouldReceive('pending')->once()->with($provisioning, 'identity.organization-person-events.v1', 2)->andReturn([]);
        $transport->shouldReceive('readGroup')->once()->with($provisioning, 'identity.organization-person-events.v1', 'identity-fair', 2)
            ->andReturn(array_map(fn (int $id): array => $this->malformedMessage("1-{$id}"), range(0, 1)));
        foreach (range(0, 1) as $id) {
            $transport->shouldReceive('publishDlq')->once()->with('platform.dlq.v1', $provisioning."|1-{$id}", Mockery::type('array'))->andReturn("2-{$id}");
            $transport->shouldReceive('ack')->once()->with($provisioning, 'identity.organization-person-events.v1', "1-{$id}");
        }
        $transport->shouldReceive('createGroup')->once()->with($status, 'identity.organization-person-events.v1');
        $transport->shouldReceive('pending')->once()->with($status, 'identity.organization-person-events.v1', 2)->andReturn([]);
        $transport->shouldReceive('readGroup')->once()->with($status, 'identity.organization-person-events.v1', 'identity-fair', 2)
            ->andReturn([$this->malformedMessage('1-0')]);
        $transport->shouldReceive('publishDlq')->once()->with('platform.dlq.v1', $status.'|1-0', Mockery::type('array'))->andReturn('3-0');
        $transport->shouldReceive('ack')->once()->with($status, 'identity.organization-person-events.v1', '1-0');
        $transport->shouldReceive('createGroup')->once()->with($updated, 'identity.organization-person-events.v1');
        $transport->shouldReceive('pending')->once()->with($updated, 'identity.organization-person-events.v1', 3)->andReturn([]);
        $transport->shouldReceive('readGroup')->once()->with($updated, 'identity.organization-person-events.v1', 'identity-fair', 3)->andReturn([]);

        $worker = new IdentityPersonStreamWorker(
            $transport,
            $this->app->make(ConsumeOrganizationPersonEventHandler::class),
        );

        $this->assertSame(3, $worker->consumeOnce('identity-fair', 6));
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-Correlation-ID' => '018f6f7d-0c00-7000-8000-000000000301'];
    }

    /** @return array{id: string, fields: array{event: string}, deliveries: int} */
    private function malformedMessage(string $id): array
    {
        return ['id' => $id, 'fields' => ['event' => 'not-json'], 'deliveries' => 3];
    }
}
