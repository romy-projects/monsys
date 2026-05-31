<x-filament-panels::page>
@php
    $d   = $this->getDashboardData();
    $fmt = fn($n) => 'Rp ' . number_format($n, 0, ',', '.');
    $branch = auth()->user()->branch;
@endphp

<div class="space-y-6">

    {{-- Branch Header --}}
    <div class="rounded-xl border border-gray-200 bg-white px-6 py-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="flex items-center gap-4">
            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-primary-100 dark:bg-primary-900">
                <span class="text-2xl">🏪</span>
            </div>
            <div>
                <p class="text-lg font-bold text-gray-900 dark:text-white">{{ $branch?->name }}</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $branch?->city }}, {{ $branch?->province }}</p>
            </div>
        </div>
    </div>

    {{-- Key Stats --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <p class="text-xs text-gray-500 dark:text-gray-400">Today's Revenue</p>
            <p class="mt-1 text-xl font-bold text-green-600 dark:text-green-400">{{ $fmt($d['todaySales']) }}</p>
            <p class="mt-0.5 text-xs text-gray-500">{{ number_format($d['todayQty']) }} pcs sold</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <p class="text-xs text-gray-500 dark:text-gray-400">Month Revenue</p>
            <p class="mt-1 text-xl font-bold text-blue-600 dark:text-blue-400">{{ $fmt($d['monthRevenue']) }}</p>
            @if($d['achievementPct'] !== null)
            <div class="mt-1.5 w-full bg-gray-100 dark:bg-gray-700 rounded-full h-1.5">
                <div class="h-1.5 rounded-full {{ $d['achievementPct'] >= 100 ? 'bg-green-500' : ($d['achievementPct'] >= 80 ? 'bg-yellow-400' : 'bg-red-400') }}"
                    style="width: {{ min($d['achievementPct'], 100) }}%"></div>
            </div>
            <p class="mt-0.5 text-xs {{ $d['achievementPct'] >= 100 ? 'text-green-600 dark:text-green-400' : ($d['achievementPct'] >= 80 ? 'text-yellow-600 dark:text-yellow-400' : 'text-red-500 dark:text-red-400') }}">
                {{ $d['achievementPct'] }}% of {{ $fmt($d['monthTarget']) }} target
            </p>
            @else
            <p class="mt-0.5 text-xs text-gray-500">{{ now()->format('M Y') }} · No target set</p>
            @endif
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <p class="text-xs text-gray-500 dark:text-gray-400">Month Costs</p>
            <p class="mt-1 text-xl font-bold text-red-600 dark:text-red-400">{{ $fmt($d['monthCosts']) }}</p>
            <p class="mt-0.5 text-xs text-gray-500">Gross: {{ $fmt($d['monthRevenue'] - $d['monthCosts']) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <p class="text-xs text-gray-500 dark:text-gray-400">Pending DOs</p>
            <p class="mt-1 text-xl font-bold {{ $d['pendingDOs'] > 0 ? 'text-warning-600 dark:text-warning-400' : 'text-gray-500' }}">
                {{ $d['pendingDOs'] }} orders
            </p>
            <p class="mt-0.5 text-xs text-gray-500">Awaiting approval</p>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

        {{-- Current Stock --}}
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-700">
                <h3 class="font-semibold text-gray-900 dark:text-white">📦 Current Stock</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400">Latest recorded stock levels</p>
            </div>
            @if($d['stocks']->isEmpty())
            <div class="px-6 py-8 text-center text-sm text-gray-400">No stock records found.</div>
            @else
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Type</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Full</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Empty</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Damaged</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Updated</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach(['3kg','5.5kg','12kg','50kg'] as $type)
                    @php $s = $d['stocks']->get($type) @endphp
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800 dark:bg-blue-900 dark:text-blue-200">{{ $type }}</span>
                        </td>
                        <td class="px-4 py-3 text-right font-semibold {{ $s && $s->qty_full < 20 ? 'text-red-600' : ($s && $s->qty_full < 50 ? 'text-yellow-600' : 'text-green-600') }}">
                            {{ $s ? number_format($s->qty_full) : '—' }}
                        </td>
                        <td class="px-4 py-3 text-right text-yellow-600 dark:text-yellow-400">{{ $s ? number_format($s->qty_empty) : '—' }}</td>
                        <td class="px-4 py-3 text-right {{ $s && $s->qty_damaged > 0 ? 'text-red-600' : 'text-gray-500' }}">{{ $s ? number_format($s->qty_damaged) : '—' }}</td>
                        <td class="px-4 py-3 text-right text-xs text-gray-400">{{ $s ? $s->recorded_at->format('d M') : '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @endif
        </div>

        {{-- In-Transit Deliveries --}}
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-700">
                <h3 class="font-semibold text-gray-900 dark:text-white">🚛 Incoming Deliveries</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400">Approved & in-transit orders</p>
            </div>
            @if($d['inTransitDOs']->isEmpty())
            <div class="px-6 py-8 text-center text-sm text-gray-400">No active deliveries.</div>
            @else
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach($d['inTransitDOs'] as $do)
                @php
                    $daysLeft = $do->eta ? now()->startOfDay()->diffInDays($do->eta, false) : null;
                    $etaColor = $daysLeft === null ? 'gray' : ($daysLeft < 0 ? 'red' : ($daysLeft <= 2 ? 'yellow' : 'green'));
                    $statusLabel = $do->status === 'in_transit' ? 'In Transit' : 'Approved';
                @endphp
                <div class="px-6 py-4">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="font-semibold text-gray-900 dark:text-white">{{ $do->do_number }}</p>
                            <p class="mt-0.5 text-xs text-gray-500">
                                From {{ $do->originBranch?->name }}
                                @if($do->expedition) · {{ $do->expedition->name }} @endif
                            </p>
                            <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                                {{ $do->cylinder_type }} · {{ number_format($do->quantity_ordered) }} pcs
                            </p>
                        </div>
                        <div class="text-right">
                            @if($do->eta)
                            <p class="text-xs text-gray-500">ETA {{ $do->eta->format('d M Y') }}</p>
                            <p class="mt-1 text-sm font-semibold {{ $etaColor === 'red' ? 'text-red-600 dark:text-red-400' : ($etaColor === 'yellow' ? 'text-yellow-600 dark:text-yellow-400' : ($etaColor === 'green' ? 'text-green-600 dark:text-green-400' : 'text-gray-400')) }}">
                                @if($daysLeft < 0){{ abs($daysLeft) }}d overdue
                                @elseif($daysLeft === 0)Today!
                                @else{{ $daysLeft }}d left
                                @endif
                            </p>
                            @else
                            <p class="text-xs text-gray-400">No ETA set</p>
                            @endif
                            <span class="mt-1 inline-block rounded-full bg-blue-100 px-2 py-0.5 text-xs text-blue-800 dark:bg-blue-900 dark:text-blue-200">{{ $statusLabel }}</span>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
            @endif
        </div>

    </div>
</div>
</x-filament-panels::page>
