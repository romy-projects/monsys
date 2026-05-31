<x-filament-panels::page>
@php
    $branches = $this->getBranches();
    $d        = $this->getAuditData();
    $isMulti  = auth()->user()->isOwnerPusat() || auth()->user()->isRegionalLeader();

    // Group rows by branch for display
    $grouped = collect($d['rows'])->groupBy(fn($r) => $r['branch']->id);
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
                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Period From</label>
                <input type="date" wire:model.live="start_date" value="{{ $this->start_date }}"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white">
            </div>
            <div class="flex-1 min-w-[160px]">
                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Period To</label>
                <input type="date" wire:model.live="end_date" value="{{ $this->end_date }}"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white">
            </div>
        </div>
        <p class="mt-3 text-xs text-gray-400">Mutations (masuk/keluar/transfer/adjustment) counted within the period · Current stock from latest StockItem record</p>
    </div>

    {{-- Audit Table --}}
    @if(empty($d['rows']))
    <div class="rounded-xl border border-gray-200 bg-white px-6 py-10 text-center text-sm text-gray-400 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        No data found for the selected filters.
    </div>
    @else

    @foreach($grouped as $branchId => $branchRows)
    @php $branch = $branchRows->first()['branch']; @endphp
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900 overflow-hidden">
        <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-700 flex items-center gap-3">
            <div class="flex h-8 w-8 items-center justify-center rounded-full bg-primary-100 dark:bg-primary-900 text-sm">🏪</div>
            <div>
                <h3 class="font-semibold text-gray-900 dark:text-white">{{ $branch->name }}</h3>
                <p class="text-xs text-gray-400">{{ $branch->city }}, {{ $branch->province }}</p>
            </div>
        </div>
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Type</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Mutations In</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Mutations Out</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Net Movement</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Current Full</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Last Recorded</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Mutation Records</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach($branchRows as $row)
                @php
                    $netPositive = $row['net_movement'] > 0;
                    $netNeutral  = $row['net_movement'] === 0;
                @endphp
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                            {{ $row['type'] }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-right text-green-600 dark:text-green-400 font-medium">
                        {{ $row['net_in'] > 0 ? '+' . number_format($row['net_in']) : '—' }}
                    </td>
                    <td class="px-4 py-3 text-right text-red-600 dark:text-red-400 font-medium">
                        {{ $row['net_out'] > 0 ? '-' . number_format($row['net_out']) : '—' }}
                    </td>
                    <td class="px-4 py-3 text-right font-bold
                        {{ $netPositive ? 'text-green-600 dark:text-green-400' : ($netNeutral ? 'text-gray-400' : 'text-red-600 dark:text-red-400') }}">
                        {{ $row['net_movement'] > 0 ? '+' : '' }}{{ number_format($row['net_movement']) }}
                    </td>
                    <td class="px-4 py-3 text-right font-semibold
                        {{ $row['actual_full'] === null ? 'text-gray-300' : ($row['actual_full'] < 20 ? 'text-red-600' : ($row['actual_full'] < 50 ? 'text-yellow-600' : 'text-green-600')) }}">
                        {{ $row['actual_full'] !== null ? number_format($row['actual_full']) : '—' }}
                    </td>
                    <td class="px-4 py-3 text-right text-xs text-gray-400">
                        {{ $row['recorded_at'] ? $row['recorded_at']->format('d M Y') : '—' }}
                    </td>
                    <td class="px-4 py-3 text-right">
                        @if($row['mutations_count'] > 0)
                        <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-gray-700 dark:text-gray-300">
                            {{ $row['mutations_count'] }}
                        </span>
                        @else
                        <span class="text-gray-300 text-xs">—</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endforeach

    @endif

</div>
</x-filament-panels::page>
