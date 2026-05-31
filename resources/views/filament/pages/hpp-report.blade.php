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
                <input type="date" wire:model.live="start_date"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white">
            </div>
            <div class="flex-1 min-w-[160px]">
                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">To</label>
                <input type="date" wire:model.live="end_date"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white">
            </div>
        </div>
        <p class="mt-2 text-xs text-gray-400">Purchase price used = price effective on the "To" date per cylinder type.</p>
    </div>

    {{-- HPP Table --}}
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-700">
            <h3 class="font-semibold text-gray-900 dark:text-white">💰 Cost of Goods (HPP) vs Revenue</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                {{ \Carbon\Carbon::parse($d['start_date'])->format('d M Y') }} — {{ \Carbon\Carbon::parse($d['end_date'])->format('d M Y') }}
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Type</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Qty Sold</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Purchase Price</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Total HPP</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Revenue</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Gross Margin</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Margin %</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($d['rows'] as $row)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 {{ ! $row['has_data'] ? 'opacity-40' : '' }}">
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                {{ $row['type'] }}
                            </span>
                            @if(! $row['has_price'])
                            <span class="ml-1 text-xs text-yellow-600">⚠ no price</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300">
                            {{ $row['qty_sold'] > 0 ? number_format($row['qty_sold']) : '—' }}
                        </td>
                        <td class="px-4 py-3 text-right text-gray-500 dark:text-gray-400">
                            {{ $row['has_price'] ? $fmt($row['purchase_price']) : '—' }}
                        </td>
                        <td class="px-4 py-3 text-right text-red-600 dark:text-red-400 font-medium">
                            {{ $row['total_hpp'] > 0 ? $fmt($row['total_hpp']) : '—' }}
                        </td>
                        <td class="px-4 py-3 text-right text-green-600 dark:text-green-400 font-medium">
                            {{ $row['total_revenue'] > 0 ? $fmt($row['total_revenue']) : '—' }}
                        </td>
                        <td class="px-4 py-3 text-right font-semibold {{ $row['gross_margin'] > 0 ? 'text-green-600 dark:text-green-400' : ($row['gross_margin'] < 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-400') }}">
                            {{ $row['has_data'] ? $fmt($row['gross_margin']) : '—' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            @if($row['margin_pct'] !== null)
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold
                                {{ $row['margin_pct'] >= 20 ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' :
                                   ($row['margin_pct'] >= 10 ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' :
                                   'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200') }}">
                                {{ $row['margin_pct'] }}%
                            </span>
                            @else
                            <span class="text-gray-300">—</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot class="border-t-2 border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <td class="px-4 py-3 font-bold text-gray-900 dark:text-white">Total</td>
                        <td class="px-4 py-3 text-right font-bold text-gray-900 dark:text-white">{{ number_format($d['totals']['qty_sold']) }}</td>
                        <td class="px-4 py-3"></td>
                        <td class="px-4 py-3 text-right font-bold text-red-700 dark:text-red-400">{{ $fmt($d['totals']['total_hpp']) }}</td>
                        <td class="px-4 py-3 text-right font-bold text-green-700 dark:text-green-400">{{ $fmt($d['totals']['total_revenue']) }}</td>
                        <td class="px-4 py-3 text-right font-bold {{ $d['totals']['gross_margin'] >= 0 ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400' }}">
                            {{ $fmt($d['totals']['gross_margin']) }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            @if($d['totals']['margin_pct'] !== null)
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-bold
                                {{ $d['totals']['margin_pct'] >= 20 ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' :
                                   ($d['totals']['margin_pct'] >= 10 ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' :
                                   'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200') }}">
                                {{ $d['totals']['margin_pct'] }}%
                            </span>
                            @endif
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    {{-- Legend --}}
    <div class="flex flex-wrap gap-4 text-xs text-gray-500 dark:text-gray-400">
        <span><span class="inline-block w-3 h-3 rounded-full bg-green-500 mr-1"></span>Margin ≥ 20% — healthy</span>
        <span><span class="inline-block w-3 h-3 rounded-full bg-yellow-400 mr-1"></span>Margin 10–19% — watch</span>
        <span><span class="inline-block w-3 h-3 rounded-full bg-red-500 mr-1"></span>Margin &lt; 10% — critical</span>
        <span class="ml-2">HPP = qty sold × purchase price at period end date</span>
    </div>

</div>
</x-filament-panels::page>
