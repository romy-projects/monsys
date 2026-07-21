<x-filament-panels::page>
@php
    $branches = $this->getBranches();
    $d        = $this->getReportData();
    $fmt      = fn($n) => 'Rp ' . number_format($n, 0, ',', '.');
    $isMulti  = auth()->user()->isOwnerPusat() || auth()->user()->isRegionalLeader();
    $hasData  = $d['total_qty'] > 0;
@endphp

<div class="space-y-6">

    {{-- Filters --}}
    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="flex flex-wrap items-end gap-4">
            @if($isMulti)
            <div class="w-full sm:w-auto sm:min-w-[200px]">
                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Branch</label>
                <select wire:model.live="branch_id"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                    <option value="">All Branches (HQ View)</option>
                    @foreach($branches as $b)
                    <option value="{{ $b->id }}">{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>
            @endif
            <div class="w-full sm:w-auto sm:min-w-[160px]">
                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">From</label>
                <input type="date" wire:model.live="start_date" value="{{ $this->start_date }}"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white">
            </div>
            <div class="w-full sm:w-auto sm:min-w-[160px]">
                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">To</label>
                <input type="date" wire:model.live="end_date" value="{{ $this->end_date }}"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white">
            </div>
        </div>
    </div>

    @if(!$hasData)
    <div class="rounded-xl border border-gray-200 bg-white px-6 py-12 text-center shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <p class="text-base text-gray-400 dark:text-gray-500">No sales data found for this period.</p>
        <p class="mt-1 text-sm text-gray-400">Try adjusting the date range or branch filter.</p>
    </div>
    @else

    {{-- KPI Cards --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total Revenue</p>
            <p class="mt-1 text-2xl font-bold text-green-600 dark:text-green-400">{{ $fmt($d['total_revenue']) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total Quantity</p>
            <p class="mt-1 text-2xl font-bold text-blue-600 dark:text-blue-400">{{ number_format($d['total_qty']) }} <span class="text-sm font-normal text-gray-400">pcs</span></p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Period</p>
            <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                {{ \Carbon\Carbon::parse($d['start_date'])->format('d M Y') }} — {{ \Carbon\Carbon::parse($d['end_date'])->format('d M Y') }}
            </p>
        </div>
    </div>

    {{-- Sales by Cylinder Type --}}
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-700">
            <h3 class="font-semibold text-gray-900 dark:text-white">📊 Sales by Cylinder Type</h3>
        </div>
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Type</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Qty (pcs)</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Revenue</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Share</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach($d['types'] as $type)
                @php $row = $d['by_type']->get($type); @endphp
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800 dark:bg-blue-900 dark:text-blue-200">{{ $type }}</span>
                    </td>
                    @if($row)
                    <td class="px-4 py-3 text-right font-semibold text-gray-900 dark:text-white">{{ number_format($row->total_qty) }}</td>
                    <td class="px-4 py-3 text-right font-semibold text-green-600 dark:text-green-400">{{ $fmt($row->total_revenue) }}</td>
                    <td class="px-4 py-3 text-right text-gray-500">{{ round(($row->total_revenue / max($d['total_revenue'], 1)) * 100, 1) }}%</td>
                    @else
                    <td class="px-4 py-3 text-right text-gray-300">—</td>
                    <td class="px-4 py-3 text-right text-gray-300">—</td>
                    <td class="px-4 py-3 text-right text-gray-300">—</td>
                    @endif
                </tr>
                @endforeach
            </tbody>
            @if($d['total_qty'] > 0)
            <tfoot class="border-t-2 border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-800">
                <tr>
                    <td class="px-4 py-3 font-bold text-gray-900 dark:text-white">Total</td>
                    <td class="px-4 py-3 text-right font-bold text-gray-900 dark:text-white">{{ number_format($d['total_qty']) }}</td>
                    <td class="px-4 py-3 text-right font-bold text-green-700 dark:text-green-400">{{ $fmt($d['total_revenue']) }}</td>
                    <td class="px-4 py-3 text-right font-bold text-gray-500">100%</td>
                </tr>
            </tfoot>
            @endif
        </table>
    </div>

    {{-- Per-Branch Breakdown (HQ view only) --}}
    @if(!empty($d['by_branch']))
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-700">
            <h3 class="font-semibold text-gray-900 dark:text-white">🏢 Sales by Branch</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Branch</th>
                        @foreach($d['types'] as $type)
                        <th class="px-3 py-3 text-right text-xs font-semibold uppercase text-gray-500">{{ $type }}</th>
                        @endforeach
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Total Qty</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Total Revenue</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($d['by_branch'] as $branchId => $items)
                    @php
                        $branch = $branches->firstWhere('id', $branchId);
                        $branchTotal = $items->sum('total_qty');
                        $branchRevenue = $items->sum('total_revenue');
                    @endphp
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                        <td class="px-4 py-3 font-medium text-gray-900 dark:text-white whitespace-nowrap">{{ $branch?->name ?? 'Branch #'.$branchId }}</td>
                        @foreach($d['types'] as $type)
                        @php $item = $items->firstWhere('cylinder_type', $type); @endphp
                        <td class="px-3 py-3 text-right {{ $item ? 'text-gray-900 dark:text-white' : 'text-gray-300' }}">
                            {{ $item ? number_format($item->total_qty) : '—' }}
                        </td>
                        @endforeach
                        <td class="px-4 py-3 text-right font-semibold text-gray-900 dark:text-white">{{ number_format($branchTotal) }}</td>
                        <td class="px-4 py-3 text-right font-semibold text-green-600 dark:text-green-400 whitespace-nowrap">{{ $fmt($branchRevenue) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- Daily Revenue Trend --}}
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-700">
            <h3 class="font-semibold text-gray-900 dark:text-white">📅 Daily Revenue Trend</h3>
        </div>
        @php $maxRev = $d['by_date']->max('total_revenue') ?: 1; @endphp
        <div class="px-4 py-4 space-y-2 max-h-80 overflow-y-auto">
            @foreach($d['by_date'] as $day)
            @php $pct = round(($day->total_revenue / $maxRev) * 100); @endphp
            <div class="flex items-center gap-3">
                <span class="w-24 shrink-0 text-xs text-gray-500">{{ \Carbon\Carbon::parse($day->sale_date)->format('d M') }}</span>
                <div class="flex-1 bg-gray-100 dark:bg-gray-700 rounded-full h-5 overflow-hidden">
                    <div class="h-5 rounded-full bg-gradient-to-r from-green-400 to-green-500 transition-all" style="width: {{ $pct }}%"></div>
                </div>
                <span class="w-32 shrink-0 text-right text-xs font-medium text-gray-700 dark:text-gray-300">{{ $fmt($day->total_revenue) }}</span>
                <span class="w-16 shrink-0 text-right text-xs text-gray-400">{{ number_format($day->total_qty) }} pcs</span>
            </div>
            @endforeach
        </div>
    </div>

    @endif
</div>
</x-filament-panels::page>