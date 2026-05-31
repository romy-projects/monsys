<x-filament-panels::page>
@php
    $notifications = $this->getNotifications();
    $unreadCount   = $notifications->whereNull('read_at')->count();
    $isPusat       = auth()->user()->isOwnerPusat() || auth()->user()->isRegionalLeader();

    $colorMap = [
        'danger'  => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
        'warning' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
        'success' => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
        'info'    => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
        'primary' => 'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300',
    ];
    $dotMap = [
        'danger'  => 'bg-red-500',
        'warning' => 'bg-yellow-400',
        'success' => 'bg-green-500',
        'info'    => 'bg-blue-500',
        'primary' => 'bg-primary-500',
    ];
@endphp

<div class="space-y-5">

    {{-- Filters bar --}}
    <div class="rounded-xl border border-gray-200 bg-white px-6 py-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="flex flex-wrap items-end gap-4">

            {{-- Read filter --}}
            <div class="flex-1 min-w-[160px]">
                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Show</label>
                <select wire:model.live="filter"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                    <option value="all">All notifications</option>
                    <option value="unread">Unread only</option>
                </select>
            </div>

            {{-- From --}}
            <div class="flex-1 min-w-[160px]">
                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">From</label>
                <input type="date" wire:model.live="from"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white">
            </div>

            {{-- Until --}}
            <div class="flex-1 min-w-[160px]">
                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Until</label>
                <input type="date" wire:model.live="until"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white">
            </div>

            {{-- Summary + mark all read --}}
            <div class="flex items-center gap-3 ml-auto">
                @if($unreadCount > 0)
                <span class="rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-700 dark:bg-red-900/40 dark:text-red-300">
                    {{ $unreadCount }} unread
                </span>
                <button wire:click="markAllRead"
                    class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
                    Mark all read
                </button>
                @else
                <span class="text-sm text-gray-400 dark:text-gray-500">All caught up</span>
                @endif
            </div>
        </div>
    </div>

    {{-- Notification list --}}
    @if($notifications->isEmpty())
    <div class="rounded-xl border border-gray-200 bg-white px-6 py-12 shadow-sm dark:border-gray-700 dark:bg-gray-900 text-center">
        <svg class="mx-auto mb-3 h-10 w-10 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
        </svg>
        <p class="text-sm text-gray-500 dark:text-gray-400">No notifications found for the selected period.</p>
    </div>
    @else
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
            @foreach($notifications as $notif)
            @php
                $data    = is_array($notif->data) ? $notif->data : json_decode($notif->data, true) ?? [];
                $title   = $data['title']   ?? $data['subject'] ?? 'System Notification';
                $body    = $data['body']    ?? $data['message'] ?? null;
                $color   = $data['color']   ?? 'info';
                $isRead  = ! is_null($notif->read_at);
                $dot     = $dotMap[$color]  ?? 'bg-gray-400';
                $badge   = $colorMap[$color] ?? 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300';
            @endphp
            <li class="flex items-start gap-4 px-5 py-4 transition {{ $isRead ? 'opacity-70' : 'bg-blue-50/30 dark:bg-blue-950/10' }}">

                {{-- Read dot --}}
                <div class="mt-1.5 flex-shrink-0">
                    @if(! $isRead)
                    <span class="block h-2.5 w-2.5 rounded-full {{ $dot }} ring-2 ring-white dark:ring-gray-900"></span>
                    @else
                    <span class="block h-2.5 w-2.5 rounded-full bg-gray-200 dark:bg-gray-700"></span>
                    @endif
                </div>

                {{-- Content --}}
                <div class="flex-1 min-w-0">
                    <div class="flex flex-wrap items-center gap-2 mb-0.5">
                        <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $title }}</span>
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $badge }}">
                            {{ ucfirst($color) }}
                        </span>
                        @if(! $isRead)
                        <span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">
                            Unread
                        </span>
                        @endif
                    </div>

                    @if($body)
                    <p class="text-sm text-gray-600 dark:text-gray-400 leading-snug">{{ $body }}</p>
                    @endif

                    <div class="mt-1 flex flex-wrap items-center gap-3 text-xs text-gray-400 dark:text-gray-500">
                        <span>{{ $notif->created_at->format('d M Y H:i') }}</span>
                        @if($isPusat && $notif->notifiable)
                        <span class="text-gray-300 dark:text-gray-600">·</span>
                        <span>{{ $notif->notifiable->name ?? ('User #' . $notif->notifiable_id) }}</span>
                        @endif
                        @if($isRead)
                        <span class="text-gray-300 dark:text-gray-600">·</span>
                        <span>Read {{ $notif->read_at->diffForHumans() }}</span>
                        @endif
                    </div>
                </div>
            </li>
            @endforeach
        </ul>

        @if($notifications->count() >= 200)
        <div class="border-t border-gray-100 dark:border-gray-800 px-5 py-3 text-center text-xs text-gray-400 dark:text-gray-500">
            Showing latest 200 notifications. Narrow the date range to see older entries.
        </div>
        @endif
    </div>
    @endif

</div>
</x-filament-panels::page>
