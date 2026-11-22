<x-filament-panels::page>
@php
    $branches = $this->getBranches();
    $report   = $this->getReport();
    $fmt      = fn($n) => 'Rp ' . number_format($n, 0, ',', '.');
    $isMulti  = auth()->user()->canAccessAllBranches();
    $statusColor = [
        'paid' => 'text-green-600 dark:text-green-400',
        'partial' => 'text-amber-600 dark:text-amber-400',
        'overdue' => 'text-red-600 dark:text-red-400',
    ];
@endphp

<div class="space-y-6">

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
            <div>
                <a href="{{ $this->getExportUrl() }}"
                   class="inline-flex items-center rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500">
                    ⬇ Export Excel
                </a>
            </div>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        @foreach([
            'Total Barang' => $report['totals']['product'],
            'Ongkos Expedisi' => $report['totals']['expedition_fee'],
            'Total Tagihan' => $report['totals']['total'],
            'Sudah Dibayar' => $report['totals']['paid'],
            'Sisa' => $report['totals']['balance'],
        ] as $label => $value)
        <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
            <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ $fmt($value) }}</p>
        </div>
        @endforeach
    </div>

    {{-- Detail --}}
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <th class="px-3 py-3">Tgl Faktur</th>
                        <th class="px-3 py-3">No Faktur</th>
                        <th class="px-3 py-3">Pangkalan</th>
                        <th class="px-3 py-3">Cabang</th>
                        <th class="px-3 py-3">Tipe</th>
                        <th class="px-3 py-3 text-right">Qty</th>
                        <th class="px-3 py-3 text-right">Harga</th>
                        <th class="px-3 py-3 text-right">Total</th>
                        <th class="px-3 py-3 text-right">Expedisi</th>
                        <th class="px-3 py-3">Tgl Bayar</th>
                        <th class="px-3 py-3">Metode</th>
                        <th class="px-3 py-3 text-right">Dibayar</th>
                        <th class="px-3 py-3 text-right">Sisa</th>
                        <th class="px-3 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse($report['rows'] as $row)
                    <tr class="text-gray-700 dark:text-gray-200">
                        <td class="px-3 py-2 whitespace-nowrap">{{ $row['issue_date'] }}</td>
                        <td class="px-3 py-2 whitespace-nowrap font-mono text-xs">{{ $row['invoice_number'] }}</td>
                        <td class="px-3 py-2">{{ $row['buyer'] }}</td>
                        <td class="px-3 py-2 whitespace-nowrap">{{ $row['branch'] }}</td>
                        <td class="px-3 py-2">{{ $row['cylinder_type'] }}</td>
                        <td class="px-3 py-2 text-right">{{ rtrim(rtrim(number_format($row['quantity'], 2, ',', '.'), '0'), ',') }}</td>
                        <td class="px-3 py-2 text-right">{{ $fmt($row['unit_price']) }}</td>
                        <td class="px-3 py-2 text-right">{{ $fmt($row['total']) }}</td>
                        <td class="px-3 py-2 text-right">{{ $row['expedition_fee'] ? $fmt($row['expedition_fee']) : '—' }}</td>
                        <td class="px-3 py-2 whitespace-nowrap">{{ $row['paid_on'] ?? '—' }}</td>
                        <td class="px-3 py-2 whitespace-nowrap">{{ $row['method'] ?: '—' }}</td>
                        <td class="px-3 py-2 text-right">{{ $fmt($row['paid']) }}</td>
                        <td class="px-3 py-2 text-right">{{ $fmt($row['balance']) }}</td>
                        <td class="px-3 py-2 whitespace-nowrap uppercase text-xs font-semibold {{ $statusColor[$row['status']] ?? 'text-gray-500' }}">
                            {{ $row['status'] }}
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="14" class="px-4 py-8 text-center text-gray-400">
                            Tidak ada penjualan pada periode ini.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
                @if(count($report['rows']) > 0)
                <tfoot class="bg-gray-50 dark:bg-gray-800">
                    <tr class="font-semibold text-gray-900 dark:text-white">
                        <td colspan="5" class="px-3 py-3 text-right">TOTAL</td>
                        <td class="px-3 py-3 text-right">{{ rtrim(rtrim(number_format($report['totals']['quantity'], 2, ',', '.'), '0'), ',') }}</td>
                        <td class="px-3 py-3"></td>
                        <td class="px-3 py-3 text-right">{{ $fmt($report['totals']['total']) }}</td>
                        <td class="px-3 py-3 text-right">{{ $fmt($report['totals']['expedition_fee']) }}</td>
                        <td colspan="2" class="px-3 py-3"></td>
                        <td class="px-3 py-3 text-right">{{ $fmt($report['totals']['paid']) }}</td>
                        <td class="px-3 py-3 text-right">{{ $fmt($report['totals']['balance']) }}</td>
                        <td class="px-3 py-3"></td>
                    </tr>
                </tfoot>
                @endif
            </table>
        </div>
    </div>
</div>
</x-filament-panels::page>
