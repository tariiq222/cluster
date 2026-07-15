<?php

namespace Tests\Feature;

use Tests\TestCase;

class DevelopmentFixtureLoginTest extends TestCase
{
    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000101';

    public function test_fixture_accounts_receive_principals_for_their_own_facilities(): void
    {
        $accountA = $this->postJson('/api/v1/auth/login', [
            'username' => 'fixture-account-a',
            'password' => 'fixture-password-a',
        ], $this->headers());

        $accountA->assertOk()
            ->assertJsonPath('data.facility', 'facility-a')
            ->assertJsonPath('data.principal.facility_id', '018f6f7d-0c00-7000-8000-000000000011');

        $accountB = $this->postJson('/api/v1/auth/login', [
            'username' => 'fixture-account-b',
            'password' => 'fixture-password-b',
        ], $this->headers());

        $accountB->assertOk()
            ->assertJsonPath('data.facility', 'facility-b')
            ->assertJsonPath('data.principal.facility_id', '018f6f7d-0c00-7000-8000-000000000012');
    }

    public function test_invalid_credentials_receive_the_same_generic_unauthorized_response(): void
    {
        $unknownAccount = $this->postJson('/api/v1/auth/login', [
            'username' => 'unknown-account',
            'password' => 'fixture-password-a',
        ], $this->headers());

        $invalidPassword = $this->postJson('/api/v1/auth/login', [
            'username' => 'fixture-account-a',
            'password' => 'incorrect-password',
        ], $this->headers());

        $unknownAccount->assertUnauthorized()
            ->assertJsonPath('detail', 'بيانات الاعتماد غير صالحة.')
            ->assertJsonMissingPath('username');

        $invalidPassword->assertUnauthorized()
            ->assertJsonPath('detail', 'بيانات الاعتماد غير صالحة.')
            ->assertJsonMissingPath('username');
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['X-Correlation-ID' => self::CORRELATION_ID];
    }
}
