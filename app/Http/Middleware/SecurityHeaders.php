<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 16A / T16-02 — baseline security response headers.
 *
 * Values live in config/security.php so they can be tuned per deployment
 * without touching code. Existing headers set further down the stack are
 * never overwritten.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach ((array) config('security.headers', []) as $header => $value) {
            if (! $response->headers->has($header)) {
                $response->headers->set($header, $value);
            }
        }

        $hsts = (array) config('security.hsts', []);

        if (($hsts['enabled'] ?? false)
            && $request->isSecure()
            && ! $response->headers->has('Strict-Transport-Security')) {
            $value = 'max-age='.(int) ($hsts['max_age'] ?? 31536000);

            if ($hsts['include_subdomains'] ?? false) {
                $value .= '; includeSubDomains';
            }

            $response->headers->set('Strict-Transport-Security', $value);
        }

        return $response;
    }
}
