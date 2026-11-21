@php
    $forced = auth()->user()?->mustChangePassword() ?? false;
@endphp

<x-filament-panels::page>
    <div class="space-y-5">

        @if ($forced)
            <div class="rounded-xl border border-amber-300 bg-amber-50 px-6 py-4 text-sm text-amber-800 shadow-sm dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-200">
                <p class="font-semibold">You must choose a new password to continue.</p>
                <p>Your password was reset by an administrator. The rest of the panel stays locked until you set a password only you know.</p>
            </div>
        @endif

        <form wire:submit="submit" class="rounded-xl border border-gray-200 bg-white px-6 py-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">Change your password</h2>
            <p class="mt-1 max-w-2xl text-sm text-gray-500 dark:text-gray-400">
                Use at least {{ app(\App\Services\UserPasswordService::class)->minimumLength() }} characters.
                You will stay signed in here, but every other browser, device and API token is signed out.
            </p>

            <div class="mt-5 space-y-4">

                <div>
                    <label for="current_password" class="block text-xs font-medium text-gray-700 dark:text-gray-300">
                        Current password
                    </label>
                    <input id="current_password" type="password" autocomplete="current-password" autofocus
                        wire:model="current_password"
                        class="mt-1 w-full max-w-sm rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white" />
                    @error('current_password')
                        <p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="block text-xs font-medium text-gray-700 dark:text-gray-300">
                        New password
                    </label>
                    <input id="password" type="password" autocomplete="new-password"
                        wire:model="password"
                        class="mt-1 w-full max-w-sm rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white" />
                    @error('password')
                        <p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password_confirmation" class="block text-xs font-medium text-gray-700 dark:text-gray-300">
                        Confirm new password
                    </label>
                    <input id="password_confirmation" type="password" autocomplete="new-password"
                        wire:model="password_confirmation"
                        class="mt-1 w-full max-w-sm rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white" />
                    @error('password_confirmation')
                        <p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>
                    @enderror
                </div>

            </div>

            <div class="mt-6 flex flex-wrap items-center gap-2">
                <button type="submit"
                    wire:loading.attr="disabled"
                    class="inline-flex items-center rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 disabled:opacity-50">
                    Update password
                </button>

                @unless ($forced)
                    <a href="{{ \Filament\Pages\Dashboard::getUrl(panel: 'admin') }}"
                        class="inline-flex items-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800">
                        Cancel
                    </a>
                @endunless
            </div>
        </form>

    </div>
</x-filament-panels::page>
