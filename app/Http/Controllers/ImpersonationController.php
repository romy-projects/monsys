<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ImpersonationService;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Phase 17 / T17-05 — web entry points for starting and stopping a
 * session-only impersonation. Both actions are audited by ImpersonationService.
 */
class ImpersonationController extends Controller
{
    public function start(Request $request, User $user, ImpersonationService $impersonation): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => [config('impersonation.require_reason') ? 'required' : 'nullable', 'string', 'max:255'],
        ]);

        $impersonation->start($user, $validated['reason'] ?? null);

        return redirect()->intended(Filament::getPanel('admin')->getUrl());
    }

    public function stop(ImpersonationService $impersonation): RedirectResponse
    {
        $impersonation->stop();

        return redirect()->intended(Filament::getPanel('admin')->getUrl());
    }
}
