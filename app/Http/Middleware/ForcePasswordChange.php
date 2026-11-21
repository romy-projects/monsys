<?php

namespace App\Http\Middleware;

use App\Filament\Pages\ChangePassword;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 18 / T18-01 — forced password rotation.
 *
 * Users flagged with `must_change_password` (administrator reset, provisioned
 * Super Admin account) may reach the "Change Password" page and can always log
 * out; every other panel page redirects them back. The page itself is a
 * Livewire component, so submitting it is not affected by this middleware.
 */
class ForcePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! method_exists($user, 'mustChangePassword') || ! $user->mustChangePassword()) {
            return $next($request);
        }

        if ($request->routeIs('filament.admin.pages.change-password')
            || $request->routeIs('filament.admin.auth.logout')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'You must change your password before continuing.',
                'must_change_password' => true,
            ], 423);
        }

        return redirect()->to(
            ChangePassword::getUrl(panel: 'admin')
        );
    }
}
