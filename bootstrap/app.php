<?php

use App\Http\Middleware\LogImpersonatedActions;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Phase 16A / T16-02 — the trusted proxy list itself comes from
        // config('security.trusted_proxies') (applied in AppServiceProvider::boot())
        // because config is not loaded yet when this callback runs. Only the
        // header set is configured here.
        $middleware->trustProxies(
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
        $middleware->throttleApi();

        // Laravel's stock guest redirect targets a route literally named
        // `login`, which this application does not have — sign-in lives on the
        // Filament panel (`/admin/login`). Every unauthenticated request that
        // does not send a JSON `Accept` header therefore crashed with a
        // RouteNotFoundException (a 500 HTML page) instead of redirecting or
        // answering 401. API callers without that header get the intended JSON
        // 401; browsers get the panel login.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*')
            ? null
            : route('filament.admin.auth.login'));

        // Phase 17 / T17-06 — audit state-changing web requests while impersonating.
        // Phase 16A / T16-02 — baseline security headers on every web + API response.
        $middleware->web(append: [
            LogImpersonatedActions::class,
            SecurityHeaders::class,
        ]);
        $middleware->api(append: [
            SecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });
        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthorized.'], 403);
            }
        });
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
            }
        });
        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Resource not found.'], 404);
            }
        });
    })->create();
