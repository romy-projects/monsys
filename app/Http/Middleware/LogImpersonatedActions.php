<?php

namespace App\Http\Middleware;

use App\Services\AuditLogger;
use App\Services\ImpersonationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 17 / T17-06 — records every state-changing request made while an
 * impersonation session is active, attributing it to both accounts.
 */
class LogImpersonatedActions
{
    private const IGNORED_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! session()->has(ImpersonationService::SESSION_KEY)) {
            return $response;
        }

        if (in_array($request->method(), self::IGNORED_METHODS, true)) {
            return $response;
        }

        if ($request->routeIs('impersonation.start', 'impersonation.stop')) {
            return $response;
        }

        $this->audit->record(
            event: 'impersonation.action',
            description: sprintf('Impersonated %s /%s', $request->method(), ltrim($request->path(), '/')),
            properties: [
                'method' => $request->method(),
                'route' => $request->route()?->getName(),
                'path' => $request->path(),
                'status' => $response->getStatusCode(),
            ],
        );

        return $response;
    }
}
