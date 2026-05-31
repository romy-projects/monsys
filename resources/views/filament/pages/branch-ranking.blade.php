<x-filament-panels::page>
@php
    $d    = $this->getRankingData();
    $fmt  = fn($n) => 'Rp ' . number_format($n, 0, ',', '.');

    $medalColors = ['text-yellow-500', 'text-gray-400', 'text-amber-600'];
    $medals      = ['🥇', '🥈', '🥉'];
@endphp

<div class="space-y-6">

    {{-- Filters --}}
    <div class="rounded-xl border border-gray-200 bg-white px-6 py-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="flex flex-wrap items-end gap-4">
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
            <div class="flex-1 min-w-[200px] flex items-end">
                <div class="rounded-lg bg-gray-100 dark:bg-gray-800 px-4 py-2 text-sm">
                    <span class="text-gray-500">Period total: </span>
                    <span class="font-bold text-green-600 dark:text-green-400">{{ $fmt($d['grand_total']) }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Ranking Table --}}
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-700">
            <h3 class="font-semibold text-gray-900 dark:text-white">🏆 Branch Sales Ranking</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400">Ranked by total revenue ·
                {{ \Carbon\Carbon::parse($d['start_date'])->format('d M Y') }} — {{ \Carbon\Carbon::parse($d['end_date'])->format('d M Y') }}</p>
        </div>

        @if($d['rows']->isEmpty())
        <div class="px-6 py-10 text-center text-sm text-gray-400">No sales data for the selected period.</div>
        @else
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-gray-500 w-14">#</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Branch</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Revenue</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Target</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Achiev.</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Qty</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Share</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500 w-40">Bar</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach($d['rows'] as $row)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 {{ $row['rank'] === 1 ? 'bg-yellow-50/50 dark:bg-yellow-900/10' : '' }}">
                    <td class="px-4 py-3 text-center">
                        @if($row['rank'] <= 3)
                        <span class="text-lg">{{ $medals[$row['rank'] - 1] }}</span>
                        @else
                        <span class="text-gray-400 font-semibold">{{ $row['rank'] }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <p class="font-semibold text-gray-900 dark:text-white">{{ $row['branch']?->name ?? '—' }}</p>
                        <p class="text-xs text-gray-400">{{ $row['branch']?->city }}</p>
                    </td>
                    <td class="px-4 py-3 text-right font-bold text-green-600 dark:text-green-400">{{ $fmt($row['total_revenue']) }}</td>
                    <td class="px-4 py-3 text-right text-gray-500 dark:text-gray-400">
                        {{ $row['target_revenue'] > 0 ? $fmt($row['target_revenue']) : '—' }}
                    </td>
                    <td class="px-4 py-3 text-right">
                        @if($row['achievement_pct'] !== null)
                        @php $pct = $row['achievement_pct'] @endphp
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold
                            {{ $pct >= 100 ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
                             : ($pct >= 80 ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200'
                             : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200') }}">
                            {{ $pct }}%
                        </span>
                        @else
                        <span class="text-gray-400 text-xs">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300">{{ number_format($row['total_qty']) }}</td>
                    <td class="px-4 py-3 text-right">
                        <span class="font-semibold {{ $row['share'] >= 30 ? 'text-green-600' : ($row['share'] >= 15 ? 'text-blue-600' : 'text-gray-500') }}">
                            {{ $row['share'] }}%
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <div class="w-full bg-gray-100 dark:bg-gray-700 rounded-full h-2">
                            <div class="h-2 rounded-full {{ $row['rank'] === 1 ? 'bg-yellow-400' : ($row['rank'] === 2 ? 'bg-gray-400' : ($row['rank'] === 3 ? 'bg-amber-500' : 'bg-blue-400')) }}"
                                style="width: {{ $row['share'] }}%"></div>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
            <tfoot class="border-t-2 border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-800">
                <tr>
                    <td colspan="2" class="px-4 py-3 font-bold text-gray-900 dark:text-white">Grand Total</td>
                    <td class="px-4 py-3 text-right font-bold text-green-700 dark:text-green-400">{{ $fmt($d['grand_total']) }}</td>
                    <td class="px-4 py-3 text-right font-bold text-gray-500 dark:text-gray-400">{{ $d['grand_target'] > 0 ? $fmt($d['grand_target']) : '—' }}</td>
                    <td class="px-4 py-3 text-right font-bold">
                        @if($d['grand_target'] > 0)
                        @php $gPct = round(($d['grand_total'] / $d['grand_target']) * 100, 1) @endphp
                        <span class="{{ $gPct >= 100 ? 'text-green-700 dark:text-green-400' : ($gPct >= 80 ? 'text-yellow-600 dark:text-yellow-400' : 'text-red-600 dark:text-red-400') }}">{{ $gPct }}%</span>
                        @else
                        <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right font-bold text-gray-700 dark:text-gray-300">{{ number_format($d['rows']->sum('total_qty')) }}</td>
                    <td class="px-4 py-3 text-right font-bold text-gray-500">100%</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
        @endif
    </div>

</div>
</x-filament-panels::page>
