<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class W12E2EFixtureSeedCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_fixture_provisioning_outside_testing(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'local');

        $exitCode = Artisan::call('e2e:w1-2:seed');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('APP_ENV=testing', Artisan::output());
    }

    public function test_it_provisions_disposable_resources_and_only_emits_machine_readable_fixture_data(): void
    {
        $exitCode = Artisan::call('e2e:w1-2:seed');

        $this->assertSame(0, $exitCode);
        $fixture = json_decode(trim(Artisan::output()), true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame([
            'identity_username',
            'identity_password',
            'import_username',
            'import_password',
            'import_position_id',
            'temporary_assignment_person_id',
            'temporary_assignment_unit_id',
            'temporary_assignment_capability',
        ], array_keys($fixture));
        $this->assertMatchesRegularExpression('/\Aw12-e2e-[0-9a-f]{10}\z/', $fixture['identity_username']);
        $this->assertGreaterThanOrEqual(40, strlen($fixture['identity_password']));
        $this->assertMatchesRegularExpression('/\Aw12-import-[0-9a-f]{8}\z/', $fixture['import_username']);
        $this->assertGreaterThanOrEqual(40, strlen($fixture['import_password']));
        $this->assertSame('records.read', $fixture['temporary_assignment_capability']);
        $this->assertDatabaseHas('users', ['id' => '018f6f7d-0c00-7000-8000-000000000021', 'username' => $fixture['identity_username'], 'status' => 'active']);
        $this->assertDatabaseHas('credentials', ['user_id' => '018f6f7d-0c00-7000-8000-000000000021']);
        $this->assertDatabaseHas('identity_development_fixture_accounts', ['id' => '018f6f7d-0c00-7000-8000-000000000021', 'username' => $fixture['import_username']]);
        $this->assertDatabaseHas('people', ['id' => $fixture['temporary_assignment_person_id'], 'status' => 'active']);
        $this->assertDatabaseHas('positions', ['id' => $fixture['import_position_id'], 'organization_unit_id' => $fixture['temporary_assignment_unit_id']]);
    }
}
