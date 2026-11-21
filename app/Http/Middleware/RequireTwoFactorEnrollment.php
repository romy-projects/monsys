<?php

namespace App\Http\Middleware;

use App\Filament\Pages\SecuritySettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 16A / T16-03 — optional two-factor enrolment enforcement.
 *
 * Does nothing unless config('security.two_factor.enforced_roles') lists an
 * access role the signed-in user holds. The "Security" page itself always
 * stays reachable so the user can actually enrol.
 */
class RequireTwoFactorEnrollment
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! method_exists($user, 'mustEnrollTwoFactor')) {
            return $next($request);
        }

        if (! $user->mustEnrollTwoFactor()) {
            return $next($request);
        }

        if ($request->routeIs('filament.admin.pages.security-settings')
            || $request->routeIs('filament.admin.pages.change-password')
            || $request->routeIs('filament.admin.auth.logout')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Two-factor authentication enrolment is required for your role.',
            ], 423);
        }

        return redirect()->to(
            SecuritySettings::getUrl(panel: 'admin')
        );
    }
}
