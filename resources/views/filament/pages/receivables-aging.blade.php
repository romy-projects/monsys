<x-filament-panels::page>
@php
    $d     = $this->getAgingData();
    $fmt   = fn($n) => 'Rp ' . number_format((float)$n, 0, ',', '.');
    $isPusat = auth()->user()->isOwnerPusat() || auth()->user()->isRegionalLeader();

    $bucketTotal   = fn($key) => $d['buckets'][$key]['items']->sum('balance');
    $bucketCount   = fn($key) => $d['buckets'][$key]['items']->count();
    $bucketPct     = fn($key) => $d['grand_total'] > 0
        ? round(($d['buckets'][$key]['items']->sum('balance') / $d['grand_total']) * 100, 1)
        : 0;

    $colorMap = [
        'green'  => ['card' => 'border-green-200 bg-green-50 dark:border-green-700 dark:bg-green-900/20',  'badge' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',  'bar' => 'bg-green-500',  'text' => 'text-green-700 dark:text-green-300'],
        'yellow' => ['card' => 'border-yellow-200 bg-yellow-50 dark:border-yellow-700 dark:bg-yellow-900/20', 'badge' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200', 'bar' => 'bg-yellow-400', 'text' => 'text-yellow-700 dark:text-yellow-300'],
        'orange' => ['card' => 'border-orange-200 bg-orange-50 dark:border-orange-700 dark:bg-orange-900/20', 'badge' => 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200', 'bar' => 'bg-orange-500', 'text' => 'text-orange-700 dark:text-orange-300'],
        'red'    => ['card' => 'border-red-200 bg-red-50 dark:border-red-700 dark:bg-red-900/20',    'badge' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',    'bar' => 'bg-red-500',    'text' => 'text-red-700 dark:text-red-300'],
    ];
@endphp

<div class="space-y-6">

    {{-- Filters --}}
    @if($isPusat)
    <div class="rounded-xl border border-gray-200 bg-white px-6 py-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="flex flex-wrap items-end gap-4">
            <div class="flex-1 min-w-[220px]">
                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Branch</label>
                <select wire:model.live="branch_id"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                    <option value="">All Branches</option>
                    @foreach($this->getBranches() as $id => $name)
                    <option value="{{ $id }}" {{ $this->branch_id == $id ? 'selected' : '' }}>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="text-sm text-gray-500 dark:text-gray-400">
                Total outstanding: <span class="font-bold text-red-600 dark:text-red-400">{{ $fmt($d['grand_total']) }}</span>
            </div>
        </div>
    </div>
    @endif

    {{-- Summary Cards --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
        @foreach($d['buckets'] as $key => $bucket)
        @php $c = $colorMap[$bucket['color']] @endphp
        <div class="rounded-xl border p-4 {{ $c['card'] }}">
            <p class="text-xs font-semibold text-gray-600 dark:text-gray-400">{{ $bucket['label'] }}</p>
            <p class="mt-2 text-lg font-bold {{ $c['text'] }}">{{ $fmt($bucketTotal($key)) }}</p>
            <p class="mt-0.5 text-xs text-gray-500">{{ $bucketCount($key) }} invoice(s)</p>
            <div class="mt-2 w-full bg-gray-200 dark:bg-gray-700 rounded-full h-1.5">
                <div class="h-1.5 rounded-full {{ $c['bar'] }}" style="width: {{ $bucketPct($key) }}%"></div>
            </div>
            <p class="mt-0.5 text-xs text-gray-400">{{ $bucketPct($key) }}% of total</p>
        </div>
        @endforeach
    </div>

    {{-- Bucket Detail Tables --}}
    @foreach($d['buckets'] as $key => $bucket)
    @if($bucket['items']->isNotEmpty())
    @php $c = $colorMap[$bucket['color']] @endphp
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-700 flex items-center gap-3">
            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold {{ $c['badge'] }}">
                {{ $bucket['label'] }}
            </span>
            <span class="text-sm font-bold text-gray-900 dark:text-white">{{ $fmt($bucketTotal($key)) }}</span>
            <span class="text-xs text-gray-400">· {{ $bucketCount($key) }} invoice(s)</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        @if($isPusat)
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Branch</th>
                        @endif
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Buyer</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Invoice</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Amount</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Paid</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Balance</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-gray-500">Due Date</th>
                        @if($key !== 'current')
                        <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-gray-500">Days Late</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($bucket['items']->sortByDesc('days_overdue') as $row)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                        @if($isPusat)
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $row['branch']?->name ?? '—' }}</td>
                        @endif
                        <td class="px-4 py-3">
                            <p class="font-semibold text-gray-900 dark:text-white">{{ $row['buyer_name'] }}</p>
                            <p class="text-xs text-gray-400">{{ ucfirst($row['buyer_type']) }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <p class="text-gray-700 dark:text-gray-300">{{ $row['invoice_number'] ?? '—' }}</p>
                            <p class="text-xs text-gray-400">{{ $row['invoice_date']->format('d M Y') }}</p>
                        </td>
                        <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300">{{ $fmt($row['amount']) }}</td>
                        <td class="px-4 py-3 text-right text-green-600 dark:text-green-400">{{ $fmt($row['paid_amount']) }}</td>
                        <td class="px-4 py-3 text-right font-bold {{ $c['text'] }}">{{ $fmt($row['balance']) }}</td>
                        <td class="px-4 py-3 text-center text-gray-700 dark:text-gray-300">{{ $row['due_date']->format('d M Y') }}</td>
                        @if($key !== 'current')
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $c['badge'] }}">
                                {{ $row['days_overdue'] }}d
                            </span>
                        </td>
                        @endif
                    </tr>
                    @endforeach
                </tbody>
                <tfoot class="border-t border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <td colspan="{{ $isPusat ? 5 : 4 }}" class="px-4 py-3 font-bold text-gray-700 dark:text-gray-300">Subtotal</td>
                        <td class="px-4 py-3 text-right font-bold {{ $c['text'] }}">{{ $fmt($bucketTotal($key)) }}</td>
                        <td @if($key !== 'current') colspan="2" @endif></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    @endif
    @endforeach

    @if($d['grand_total'] == 0)
    <div class="rounded-xl border border-gray-200 bg-white px-6 py-12 text-center shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <p class="text-gray-400">No outstanding receivables found.</p>
    </div>
    @endif

</div>
</x-filament-panels::page>
