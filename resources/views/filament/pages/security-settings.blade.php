@php
    $user = auth()->user();
    $enabled = $user?->hasTwoFactorEnabled() ?? false;
    $required = $user?->mustEnrollTwoFactor() ?? false;
@endphp

<x-filament-panels::page>
    <div class="space-y-5">

        @if ($required)
            <div class="rounded-xl border border-amber-300 bg-amber-50 px-6 py-4 text-sm text-amber-800 shadow-sm dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-200">
                <p class="font-semibold">Two-factor authentication is required for your role.</p>
                <p>Enable it below to regain access to the rest of the panel.</p>
            </div>
        @endif

        {{-- Status --}}
        <div class="rounded-xl border border-gray-200 bg-white px-6 py-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">Two-factor authentication</h2>
                    <p class="mt-1 max-w-2xl text-sm text-gray-500 dark:text-gray-400">
                        Add a one-time code from an authenticator app (Google Authenticator, Authy, 1Password, …)
                        on top of your password. This is optional and can be turned off at any time.
                    </p>
                </div>

                <span @class([
                    'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold',
                    'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' => $enabled,
                    'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' => ! $enabled,
                ])>
                    {{ $enabled ? 'Enabled' : 'Not enabled' }}
                </span>
            </div>
        </div>

        {{-- One-time display of freshly issued recovery codes --}}
        @if ($recoveryCodes !== [])
            <div class="rounded-xl border border-green-300 bg-green-50 px-6 py-5 shadow-sm dark:border-green-700 dark:bg-green-900/30">
                <h3 class="text-sm font-semibold text-green-800 dark:text-green-200">
                    Recovery codes — copy them now, they will not be shown again
                </h3>
                <p class="mt-1 text-xs text-green-700 dark:text-green-300">
                    Each code can be used once instead of an authenticator code. Store them somewhere safe.
                </p>

                <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
                    @foreach ($recoveryCodes as $recoveryCode)
                        <code class="rounded-lg bg-white px-3 py-2 text-center font-mono text-sm text-gray-800 shadow-sm dark:bg-gray-950 dark:text-gray-100">
                            {{ $recoveryCode }}
                        </code>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Enrolment --}}
        @if (! $enabled && ! $enrolling)
            <div class="rounded-xl border border-gray-200 bg-white px-6 py-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <button type="button"
                    wire:click="startEnrollment"
                    wire:loading.attr="disabled"
                    class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 disabled:opacity-50">
                    Enable two-factor authentication
                </button>
            </div>
        @endif

        @if ($enrolling)
            <div class="rounded-xl border border-gray-200 bg-white px-6 py-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Scan this QR code</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Add the account to your authenticator app, then enter the 6-digit code it shows.
                </p>

                <div class="mt-4 flex flex-col gap-6 sm:flex-row sm:items-start">
                    @if ($qrSvg)
                        <div class="rounded-xl bg-white p-3 shadow-sm">
                            {!! $qrSvg !!}
                        </div>
                    @endif

                    <div class="flex-1 space-y-4">
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                Or enter this key manually
                            </p>
                            <code class="mt-1 block break-all rounded-lg bg-gray-100 px-3 py-2 font-mono text-sm text-gray-800 dark:bg-gray-950 dark:text-gray-100">
                                {{ $secret }}
                            </code>
                        </div>

                        <form wire:submit="confirmEnrollment" class="space-y-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">6-digit code</label>
                                <input type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                                    wire:model="code"
                                    class="mt-1 w-40 rounded-lg border border-gray-300 bg-white px-3 py-2 text-center font-mono text-lg tracking-widest shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white" />
                                @error('code')
                                    <p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                <button type="submit"
                                    class="inline-flex items-center rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500">
                                    Confirm &amp; enable
                                </button>
                                <button type="button"
                                    wire:click="cancelEnrollment"
                                    class="inline-flex items-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800">
                                    Cancel
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif

        {{-- Management for enrolled users --}}
        @if ($enabled && ! $enrolling)
            <div class="rounded-xl border border-gray-200 bg-white px-6 py-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Manage</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Confirm your password to regenerate recovery codes or turn two-factor authentication off.
                </p>

                <div class="mt-3">
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">Current password</label>
                    <input type="password" autocomplete="current-password"
                        wire:model="password"
                        class="mt-1 w-full max-w-sm rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white" />
                    @error('password')
                        <p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <button type="button"
                        wire:click="regenerateRecoveryCodes"
                        class="inline-flex items-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800">
                        Regenerate recovery codes
                    </button>

                    <button type="button"
                        wire:click="disableTwoFactorAuthentication"
                        wire:confirm="Turn off two-factor authentication for this account?"
                        class="inline-flex items-center rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500">
                        Disable two-factor authentication
                    </button>
                </div>
            </div>
        @endif

    </div>
</x-filament-panels::page>
