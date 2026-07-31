<x-filament-panels::page>
@php
    $branches = $this->getBranches();
    $ledger   = $this->getLedger();
    $types    = $ledger['types'];
    $result   = $ledger['result'];
    $totals   = $ledger['totals'];
    $count    = $ledger['count'];
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
            <div class="flex-1 min-w-[150px]">
                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Cylinder Type</label>
                <select wire:model.live="cylinder_type"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                    <option value="">All Types</option>
                    <option value="3kg">3 kg</option>
                    <option value="5.5kg">5.5 kg</option>
                    <option value="12kg">12 kg</option>
                    <option value="50kg">50 kg</option>
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

    {{-- Ledger Tables per Type --}}
    @foreach($types as $type)
    @php $data = $result[$type] ?? null; @endphp
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900 overflow-x-auto">
        <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-700">
            <h3 class="font-semibold text-gray-900 dark:text-white">
                <span class="inline-flex items-center rounded-full bg-blue-100 px-2.5 py-0.5 text-sm font-medium text-blue-800 dark:bg-blue-900 dark:text-blue-200">{{ $type }}</span>
                — Saldo Akhir: <span class="text-green-600 font-bold">{{ number_format($totals[$type] ?? 0) }}</span> pcs
            </h3>
        </div>

        @if(!$data || empty($data['entries']))
        <div class="px-6 py-8 text-center text-sm text-gray-400">No records found for {{ $type }}.</div>
        @else
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Tanggal</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">No SO</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Uraian</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Debit (Masuk)</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Kredit (Keluar)</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Saldo</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Keterangan</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach($data['entries'] as $entry)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                    <td class="px-4 py-3 whitespace-nowrap text-gray-700 dark:text-gray-300">
                        {{ $entry['transaction_date']?->format('d/m/Y') }}
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap font-medium text-gray-900 dark:text-white">
                        {{ $entry['so_number'] ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300 max-w-xs truncate">
                        {{ $entry['description'] }}
                        @if($entry['branch_name'])
                        <span class="block text-xs text-gray-400">{{ $entry['branch_name'] }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-center font-semibold {{ $entry['direction'] === 'debit' ? 'text-green-600' : 'text-gray-300' }}">
                        {{ $entry['direction'] === 'debit' ? number_format($entry['quantity']) : '—' }}
                    </td>
                    <td class="px-4 py-3 text-center font-semibold {{ $entry['direction'] === 'kredit' ? 'text-red-600' : 'text-gray-300' }}">
                        {{ $entry['direction'] === 'kredit' ? number_format($entry['quantity']) : '—' }}
                    </td>
                    <td class="px-4 py-3 text-center font-bold text-gray-900 dark:text-white">
                        {{ number_format($entry['balance']) }}
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">
                        @if($entry['handled_by']) {{ $entry['handled_by'] }} @endif
                        @if($entry['container_no'])
                        <span class="block">Cont: {{ $entry['container_no'] }}</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
            <tfoot class="border-t-2 border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-800">
                <tr>
                    <td colspan="3" class="px-4 py-3 font-bold text-gray-900 dark:text-white">Saldo Akhir</td>
                    <td class="px-4 py-3 text-center font-bold text-green-700 dark:text-green-400">
                        {{ number_format($data['entries']->where('direction', 'debit')->sum('quantity')) }}
                    </td>
                    <td class="px-4 py-3 text-center font-bold text-red-700 dark:text-red-400">
                        {{ number_format($data['entries']->where('direction', 'kredit')->sum('quantity')) }}
                    </td>
                    <td class="px-4 py-3 text-center font-bold text-gray-900 dark:text-white">
                        {{ number_format($data['balance']) }}
                    </td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
        @endif
    </div>
    @endforeach

    @if($count === 0)
    <div class="rounded-xl border border-gray-200 bg-white px-6 py-12 shadow-sm dark:border-gray-700 dark:bg-gray-900 text-center">
        <p class="text-gray-400 dark:text-gray-500">Belum ada data sirkulasi tabung.</p>
        <p class="text-xs text-gray-400 mt-1">Mulai dengan menambahkan transaksi pertama di menu "Sirkulasi Tabung".</p>
    </div>
    @endif
</div>
</x-filament-panels::page>