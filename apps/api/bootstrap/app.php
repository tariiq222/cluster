<?php

use App\Http\Middleware\EnforcePlatformMaintenance;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Shared\Http\ProblemEnvelope;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/web.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API routes use the stateless middleware group; login opts into a session below.
        $middleware->api(prepend: [EnforcePlatformMaintenance::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->renderable(function (NotFoundHttpException $e, Request $request) {
            return ProblemEnvelope::make(
                404,
                'resource-not-found',
                'Not Found',
                $request->header('X-Correlation-ID'),
                ['detail' => 'The requested resource was not found.'],
            );
        });
        $exceptions->renderable(function (MethodNotAllowedHttpException $e, Request $request) {
            return ProblemEnvelope::make(
                405,
                'method-not-allowed',
                'Method Not Allowed',
                $request->header('X-Correlation-ID'),
                ['detail' => 'The HTTP method is not supported for this resource.'],
            );
        });
        $exceptions->renderable(function (ThrottleRequestsException $e, Request $request) {
            return ProblemEnvelope::make(
                429,
                'too-many-requests',
                'Too Many Requests',
                $request->header('X-Correlation-ID'),
                ['detail' => 'Too many requests; retry later.'],
            );
        });
        $exceptions->renderable(function (AuthenticationException $e, Request $request) {
            return ProblemEnvelope::make(
                401,
                'authentication-required',
                'Unauthorized',
                $request->header('X-Correlation-ID'),
                ['detail' => 'Authentication is required.'],
            );
        });
        $exceptions->renderable(function (ValidationException $e, Request $request) {
            return ProblemEnvelope::make(
                422,
                'validation-failed',
                'Unprocessable Content',
                $request->header('X-Correlation-ID'),
                ['detail' => 'The request payload is invalid.', 'errors' => $e->errors()],
            );
        });
        $exceptions->renderable(function (HttpExceptionInterface $e, Request $request) {
            return ProblemEnvelope::make(
                $e->getStatusCode(),
                'http-error',
                $e->getStatusCode().' '.Request::getHttpStatusText($e->getStatusCode()),
                $request->header('X-Correlation-ID'),
                ['detail' => $e->getMessage() !== '' ? $e->getMessage() : 'The request could not be completed.'],
            );
        });
        $exceptions->renderable(function (Throwable $e, Request $request) {
            return ProblemEnvelope::make(
                500,
                'internal-server-error',
                'Internal Server Error',
                $request->header('X-Correlation-ID'),
                ['detail' => 'The server could not complete the request.'],
            );
        });
    })->create();
