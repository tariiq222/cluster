<?php

namespace Modules\Identity\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Identity\Contracts\PrincipalContext;
use Modules\Identity\Contracts\ResolvePrincipalContext;
use Modules\Identity\Features\Sessions\Http\SelectMyScopeController;
use Tests\TestCase;

final class ScopeSelectionHttpAdapterTest extends TestCase
{
    use RefreshDatabase;

    public const USER_ID = '018f6f7d-0c00-7000-0000-000000000601';

    public const SESSION_ID = '018f6f7d-0c00-7000-0000-000000000602';

    public const FACILITY_A = '018f6f7d-0c00-7000-8000-000000000611';

    public const FACILITY_B = '018f6f7d-0c00-7000-8000-000000000612';

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000613';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('users')->insert([
            'id' => self::USER_ID,
            'username' => 'scope.selection',
            'person_id' => null,
            'person_version' => null,
            'display_name_ar' => 'اختبار النطاق',
            'display_name_en' => 'Scope Selection',
            'status' => 'active',
            'must_change_password' => false,
            'password_version' => 1,
            'last_login_at' => null,
            'failed_login_count' => 0,
            'locked_until' => null,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('identity_sessions')->insert([
            'id' => self::SESSION_ID,
            'user_id' => self::USER_ID,
            'token_hash' => hash('sha256', 'scope-selection-session'),
            'password_version' => 1,
            'issued_at' => now(),
            'expires_at' => now()->addHour(),
            'revoked_at' => null,
            'last_seen_at' => null,
            'metadata' => json_encode(['scope_version' => 1], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->app->bind(ResolvePrincipalContext::class, fn (): ResolvePrincipalContext => new FakeScopePrincipalContext);
        Route::put('/_test/me/scope', SelectMyScopeController::class);
    }

    public function test_second_write_with_the_first_write_etag_is_rejected_and_state_is_not_overwritten(): void
    {
        $first = $this->putJson('/_test/me/scope', [
            'scope_type' => 'facility',
            'scope_id' => self::FACILITY_A,
        ], $this->headers('scope-first', '"1"'))->assertOk()->assertHeader('ETag', '"2"');

        $this->putJson('/_test/me/scope', [
            'scope_type' => 'facility',
            'scope_id' => self::FACILITY_B,
        ], $this->headers('scope-second', '"1"'))
            ->assertStatus(412)
            ->assertJsonPath('type', 'https://cluster.example/problems/precondition-failed');

        $metadata = json_decode((string) DB::table('identity_sessions')->where('id', self::SESSION_ID)->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(2, $metadata['scope_version']);
        $this->assertSame(['scope_type' => 'facility', 'scope_id' => self::FACILITY_A], $metadata['selected_scope']);
        $this->assertSame(1, DB::table('identity_idempotency_keys')->count());
        $this->assertSame('"2"', $first->headers->get('ETag'));
    }

    /** @return array<string, string> */
    private function headers(string $idempotencyKey, string $etag): array
    {
        return [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'Idempotency-Key' => $idempotencyKey,
            'If-Match' => $etag,
        ];
    }
}

final class FakeScopePrincipalContext implements ResolvePrincipalContext
{
    public function resolve(Request $request): PrincipalContext
    {
        $request->attributes->set('identity.session', ['session_id' => ScopeSelectionHttpAdapterTest::SESSION_ID]);

        return new PrincipalContext(
            ScopeSelectionHttpAdapterTest::USER_ID,
            null,
            'active',
            [],
            [ScopeSelectionHttpAdapterTest::FACILITY_A, ScopeSelectionHttpAdapterTest::FACILITY_B],
            [],
            null,
            null,
            false,
        );
    }

    public function resolveSelectedScope(Request $request): ?array
    {
        return null;
    }

    public function persistSelectedScope(Request $request, string $scopeType, string $scopeId): void {}
}
