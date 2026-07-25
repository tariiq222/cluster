<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\RequireIdentitySessionPrincipal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

final class RequireIdentitySessionPrincipalTest extends TestCase
{
    private const USER_ID = '018f6f7d-0c00-7000-8000-000000000021';
    private const OTHER_USER_ID = '018f6f7d-0c00-7000-8000-000000000022';
    private const SESSION_ID = '018f6f7d-0c00-7000-8000-000000000031';

    public function test_coherent_session_and_principal_binding_passes_through_verbatim(): void
    {
        $request = $this->requestWithBinding(self::USER_ID, self::USER_ID);
        $expected = response('next-called', 202);

        $response = $this->app->make(RequireIdentitySessionPrincipal::class)->handle(
            $request,
            function (Request $passed) use ($request, $expected): mixed {
                $this->assertSame($request, $passed);

                return $expected;
            },
        );

        $this->assertSame($expected, $response);
    }

    public function test_missing_session_attribute_returns_401_problem_without_invoking_next(): void
    {
        $request = Request::create('/api/v1/identity/password', 'POST');
        $request->attributes->set('identity.principal', ['user_id' => self::USER_ID]);
        $nextCalled = false;

        $response = $this->app->make(RequireIdentitySessionPrincipal::class)->handle(
            $request,
            function (Request $passed) use (&$nextCalled): mixed {
                $nextCalled = true;

                return response('must-not-be-called');
            },
        );

        $this->assertFalse($nextCalled);
        $this->assertAuthenticationRequired($response);
    }

    public function test_mismatched_user_id_returns_401_without_invoking_next(): void
    {
        $request = $this->requestWithBinding(self::USER_ID, self::OTHER_USER_ID);
        $nextCalled = false;

        $response = $this->app->make(RequireIdentitySessionPrincipal::class)->handle(
            $request,
            function (Request $passed) use (&$nextCalled): mixed {
                $nextCalled = true;

                return response('must-not-be-called');
            },
        );

        $this->assertFalse($nextCalled);
        $this->assertAuthenticationRequired($response);
    }

    private function requestWithBinding(string $sessionUserId, string $principalUserId): Request
    {
        $request = Request::create('/api/v1/identity/password', 'POST');
        $request->attributes->set('identity.session', [
            'user_id' => $sessionUserId,
            'session_id' => self::SESSION_ID,
            'restricted' => true,
        ]);
        $request->attributes->set('identity.principal', ['user_id' => $principalUserId]);

        return $request;
    }

    private function assertAuthenticationRequired(JsonResponse $response): void
    {
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));
        $this->assertSame([
            'type' => 'https://cluster.example/problems/authentication-required',
            'title' => 'Unauthorized',
            'status' => 401,
            'detail' => 'Authentication is required.',
        ], $response->getData(true));
    }
}
