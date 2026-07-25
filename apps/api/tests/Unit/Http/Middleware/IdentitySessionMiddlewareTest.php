<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\IdentityRequestAttributes;
use App\Http\Middleware\IdentitySessionMiddleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Features\Sessions\Contracts\ResolveSession;
use Modules\Identity\Features\Sessions\Contracts\TrustedRequestBindingContext;
use Tests\TestCase;

final class IdentitySessionMiddlewareTest extends TestCase
{
    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000601';

    private const USER_ID = '018f6f7d-0c00-7000-8000-000000000621';

    private const SESSION_ID = '018f6f7d-0c00-7000-8000-000000000631';

    protected function setUp(): void
    {
        parent::setUp();

        $userId = self::USER_ID;
        $sessionId = self::SESSION_ID;

        $this->app->bind(ResolveSession::class, static function () use ($userId, $sessionId): ResolveSession {
            return new class($userId, $sessionId) implements ResolveSession {
                public function __construct(
                    private readonly string $userId,
                    private readonly string $sessionId,
                ) {
                }

                public function resolve(string $rawSessionToken, TrustedRequestBindingContext $context): ?array
                {
                    return [
                        'user_id' => $this->userId,
                        'session_id' => $this->sessionId,
                        'restricted' => true,
                    ];
                }

                public function validateCsrf(string $rawSessionToken, string $rawCsrfToken, TrustedRequestBindingContext $context): bool
                {
                    return false;
                }
            };
        });
    }

    public function test_validated_correlation_id_is_stored_on_the_request_attribute(): void
    {
        $request = Request::create('/api/v1/tasks', 'GET');
        $request->headers->set('X-Correlation-ID', self::CORRELATION_ID);
        $request->cookies->set('cluster_identity_session', 'opaque-session-token');

        $nextCalled = false;
        $response = $this->app->make(IdentitySessionMiddleware::class)->handle(
            $request,
            function (Request $passed) use (&$nextCalled): mixed {
                $nextCalled = true;
                $this->assertSame(
                    self::CORRELATION_ID,
                    $passed->attributes->get(IdentityRequestAttributes::CORRELATION_ID),
                );
                $this->assertSame(
                    self::USER_ID,
                    $passed->attributes->get(IdentityRequestAttributes::SESSION)['user_id'] ?? null,
                );
                $this->assertSame(
                    ['user_id' => self::USER_ID],
                    $passed->attributes->get(IdentityRequestAttributes::PRINCIPAL),
                );

                return response('next-called', 204);
            },
        );

        $this->assertTrue($nextCalled);
        $this->assertSame(204, $response->getStatusCode());
    }

    public function test_missing_correlation_header_short_circuits_with_400_and_does_not_set_attribute(): void
    {
        $request = Request::create('/api/v1/tasks', 'GET');
        $request->cookies->set('cluster_identity_session', 'opaque-session-token');
        $nextCalled = false;

        $response = $this->app->make(IdentitySessionMiddleware::class)->handle(
            $request,
            function () use (&$nextCalled): never {
                $nextCalled = true;
            },
        );

        $this->assertFalse($nextCalled);
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));
        $this->assertNull($request->attributes->get(IdentityRequestAttributes::CORRELATION_ID));
        $this->assertNotSame(self::CORRELATION_ID, $response->headers->get('X-Correlation-ID'));
    }

    public function test_session_resolution_failure_returns_401_with_request_correlation_id(): void
    {
        $this->app->bind(ResolveSession::class, static fn (): ResolveSession => new class implements ResolveSession {
            public function resolve(string $rawSessionToken, TrustedRequestBindingContext $context): ?array
            {
                return null;
            }

            public function validateCsrf(string $rawSessionToken, string $rawCsrfToken, TrustedRequestBindingContext $context): bool
            {
                return false;
            }
        });

        $request = Request::create('/api/v1/tasks', 'GET');
        $request->headers->set('X-Correlation-ID', self::CORRELATION_ID);
        $nextCalled = false;

        $response = $this->app->make(IdentitySessionMiddleware::class)->handle(
            $request,
            function () use (&$nextCalled): never {
                $nextCalled = true;
            },
        );

        $this->assertFalse($nextCalled);
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(self::CORRELATION_ID, $response->headers->get('X-Correlation-ID'));
    }
}