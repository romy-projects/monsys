<x-filament-panels::page>
@php
    $logs = $this->getLogs();
    $eventColors = [
        'impersonation.started'     => 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200',
        'impersonation.stopped'     => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
        'impersonation.action'      => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
        'user.access_role_changed'  => 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200',
        'role.created'              => 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200',
        'role.updated'              => 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200',
    ];
@endphp

<div class="space-y-6">

    {{-- Filters --}}
    <div class="rounded-xl border border-gray-200 bg-white px-6 py-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="flex flex-wrap items-end gap-4">
            <div class="flex-1 min-w-[220px]">
                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Search</label>
                <input type="text" wire:model.live.debounce.400ms="search" placeholder="Description, user or IP"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white">
            </div>
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Event</label>
                <select wire:model.live="event"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                    <option value="">All events</option>
                    @foreach (\App\Filament\Pages\ImpersonationAudit::eventOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex-1 min-w-[150px]">
                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">From Date</label>
                <input type="date" wire:model.live="date_from"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white">
            </div>
            <div class="flex-1 min-w-[150px]">
                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Until Date</label>
                <input type="date" wire:model.live="date_until"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white">
            </div>
        </div>
    </div>

    {{-- Audit rows --}}
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="border-b border-gray-100 dark:border-gray-700">
                <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <th class="px-4 py-3">Time</th>
                    <th class="px-4 py-3">Event</th>
                    <th class="px-4 py-3">Effective user</th>
                    <th class="px-4 py-3">Started by</th>
                    <th class="px-4 py-3">Description</th>
                    <th class="px-4 py-3">IP</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($logs as $log)
                <tr class="text-gray-700 dark:text-gray-200">
                    <td class="px-4 py-3 whitespace-nowrap">{{ $log->created_at?->format('d M Y H:i:s') }}</td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $eventColors[$log->event] ?? 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200' }}">
                            {{ $log->event }}
                        </span>
                    </td>
                    <td class="px-4 py-3">{{ $log->user?->name ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $log->impersonator?->name ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $log->description }}</td>
                    <td class="px-4 py-3 whitespace-nowrap">{{ $log->ip_address ?? '—' }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                        No audit entries match the current filters.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>
        {{ $logs->links() }}
    </div>
</div>
</x-filament-panels::page>
