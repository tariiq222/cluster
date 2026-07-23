<?php

namespace Tests\Feature;

use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\PlatformSettings\Features\Maintenance\Handler\MaintenanceWindowHandler;
use Tests\TestCase;

final class PlatformMaintenanceHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_a_valid_internal_worker_credential_bypasses_active_maintenance(): void
    {
        $this->app->make(MaintenanceWindowHandler::class)->schedule(
            '019f8e3b-3368-7192-85a6-3da3949fd753',
            new DateTimeImmutable('-1 minute'),
            new DateTimeImmutable('+10 minutes'),
            'صيانة مجدولة',
            'Scheduled maintenance',
        );
        $versionId = '019f8e3b-3368-7192-85a6-3da3949fd754';
        $headers = [
            'X-Correlation-ID' => '019f8e3b-3368-7192-85a6-3da3949fd755',
            'Idempotency-Key' => 'worker-maintenance-test',
        ];

        $blocked = $this->postJson('/api/v1/internal/documents/versions/'.$versionId.'/scan', [], $headers);
        $blocked->assertStatus(503)->assertHeader('Retry-After');

        $allowed = $this->postJson('/api/v1/internal/documents/versions/'.$versionId.'/scan', [], [
            ...$headers,
            'X-Documents-Worker-Token' => str_repeat('t', 32),
        ]);
        $this->assertNotSame(503, $allowed->getStatusCode());
    }

    public function test_registered_middleware_allows_reads_and_login_but_returns_problem_details_for_an_authenticated_mutation(): void
    {
        $this->app->make(MaintenanceWindowHandler::class)->schedule(
            '019f8e3b-3368-7192-85a6-3da3949fd753',
            new DateTimeImmutable('-1 minute'),
            new DateTimeImmutable('+10 minutes'),
            'صيانة مجدولة',
            'Scheduled maintenance',
        );
        $headers = ['X-Correlation-ID' => '019f8e3b-3368-7192-85a6-3da3949fd756'];
        $login = $this->postJson('/api/v1/auth/login', [
            'username' => 'fixture-account-a',
            'password' => 'fixture-password-a',
        ], $headers)->assertOk();
        $token = (string) $login->json('data.access_token');

        $this->withToken($token)->getJson('/api/v1/documents', $headers)->assertStatus(200);
        $this->withToken($token)->postJson('/api/v1/documents/uploads', [], $headers)
            ->assertStatus(503)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertHeader('Retry-After');
    }
}
