<x-filament-panels::page>
@php
    $branches = $this->getBranches();
    $d        = $this->getReportData();
    $fmt      = fn($n) => 'Rp ' . number_format($n, 0, ',', '.');
    $isMulti  = auth()->user()->isOwnerPusat() || auth()->user()->isRegionalLeader();
@endphp

<div class="space-y-6">

    {{-- Filters --}}
    <div class="rounded-xl border border-gray-200 bg-white px-6 py-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="flex flex-wrap items-end gap-4">
            @if($isMulti)
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Branch</label>
                <select wire:model.live="branch_id"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                    <option value="">All Branches</option>
                    @foreach($branches as $b)
                    <option value="{{ $b->id }}">{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>
            @endif
            <div class="flex-1 min-w-[160px]">
                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">From</label>
                <input type="date" wire:model.live="start_date" value="{{ $this->start_date }}"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white">
            </div>
            <div class="flex-1 min-w-[160px]">
                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">To</label>
                <input type="date" wire:model.live="end_date" value="{{ $this->end_date }}"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white">
            </div>
        </div>
    </div>

    {{-- KPI Cards --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <p class="text-xs text-gray-500 dark:text-gray-400">Total Revenue</p>
            <p class="mt-1 text-xl font-bold text-green-600 dark:text-green-400">{{ $fmt($d['total_revenue']) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <p class="text-xs text-gray-500 dark:text-gray-400">Total Quantity</p>
            <p class="mt-1 text-xl font-bold text-blue-600 dark:text-blue-400">{{ number_format($d['total_qty']) }} pcs</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900 col-span-2 lg:col-span-1">
            <p class="text-xs text-gray-500 dark:text-gray-400">Period</p>
            <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                {{ \Carbon\Carbon::parse($d['start_date'])->format('d M Y') }} — {{ \Carbon\Carbon::parse($d['end_date'])->format('d M Y') }}
            </p>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

        {{-- By Cylinder Type --}}
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-700">
                <h3 class="font-semibold text-gray-900 dark:text-white">📊 Sales by Cylinder Type</h3>
            </div>
            @if($d['by_type']->isEmpty())
            <div class="px-6 py-8 text-center text-sm text-gray-400">No sales data for this period.</div>
            @else
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Type</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Qty</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Revenue</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Share</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($d['types'] as $type)
                    @php $row = $d['by_type']->get($type); @endphp
                    @if($row)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800 dark:bg-blue-900 dark:text-blue-200">{{ $type }}</span>
                        </td>
                        <td class="px-4 py-3 text-right font-semibold text-gray-900 dark:text-white">{{ number_format($row->total_qty) }}</td>
                        <td class="px-4 py-3 text-right font-semibold text-green-600 dark:text-green-400">{{ $fmt($row->total_revenue) }}</td>
                        <td class="px-4 py-3 text-right text-gray-500">
                            @if($d['total_revenue'] > 0)
                            {{ round(($row->total_revenue / $d['total_revenue']) * 100, 1) }}%
                            @else —
                            @endif
                        </td>
                    </tr>
                    @endif
                    @endforeach
                </tbody>
                <tfoot class="border-t-2 border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <td class="px-4 py-3 font-bold text-gray-900 dark:text-white">Total</td>
                        <td class="px-4 py-3 text-right font-bold text-gray-900 dark:text-white">{{ number_format($d['total_qty']) }}</td>
                        <td class="px-4 py-3 text-right font-bold text-green-700 dark:text-green-400">{{ $fmt($d['total_revenue']) }}</td>
                        <td class="px-4 py-3 text-right font-bold text-gray-500">100%</td>
                    </tr>
                </tfoot>
            </table>
            @endif
        </div>

        {{-- Daily Trend --}}
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-700">
                <h3 class="font-semibold text-gray-900 dark:text-white">📅 Daily Revenue Trend</h3>
            </div>
            @if($d['by_date']->isEmpty())
            <div class="px-6 py-8 text-center text-sm text-gray-400">No daily data for this period.</div>
            @else
            @php $maxRev = $d['by_date']->max('total_revenue') ?: 1; @endphp
            <div class="px-4 py-4 space-y-2 max-h-80 overflow-y-auto">
                @foreach($d['by_date'] as $day)
                @php $pct = round(($day->total_revenue / $maxRev) * 100); @endphp
                <div class="flex items-center gap-3">
                    <span class="w-20 shrink-0 text-xs text-gray-500">{{ \Carbon\Carbon::parse($day->sale_date)->format('d M') }}</span>
                    <div class="flex-1 bg-gray-100 dark:bg-gray-700 rounded-full h-4 overflow-hidden">
                        <div class="h-4 rounded-full bg-green-500 dark:bg-green-600 transition-all" style="width: {{ $pct }}%"></div>
                    </div>
                    <span class="w-28 shrink-0 text-right text-xs font-medium text-gray-700 dark:text-gray-300">{{ $fmt($day->total_revenue) }}</span>
                </div>
                @endforeach
            </div>
            @endif
        </div>

    </div>
</div>
</x-filament-panels::page>
