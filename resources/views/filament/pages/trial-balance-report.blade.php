<x-filament-panels::page>
@php
    $branches = $this->getBranches();
    $report   = $this->getReport();
    $rows     = $report['rows'];
    $totals   = $report['totals'];
    $fmt      = fn($n) => 'Rp ' . number_format($n, 0, ',', '.');
    $isMulti  = auth()->user()->canAccessAllBranches();
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
        <p class="mt-2 text-xs text-gray-400">
            Neraca Saldo dibaca dari jurnal yang sudah diposting. Saldo awal = seluruh jurnal sebelum
            {{ $report['period']['start'] }} (termasuk jurnal &ldquo;Saldo Awal&rdquo;), mutasi = jurnal periode ini.
            Jalankan posting jurnal terlebih dahulu agar periode ini terisi.
        </p>
    </div>

    {{-- Balance indicator --}}
    <div class="rounded-xl border px-6 py-4 shadow-sm
        {{ $totals['closing_balanced'] ? 'border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-950' : 'border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-950' }}">
        <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
            <span class="font-semibold {{ $totals['closing_balanced'] ? 'text-green-800 dark:text-green-200' : 'text-red-800 dark:text-red-200' }}">
                {{ $totals['closing_balanced'] ? '✅ Saldo akhir seimbang' : '⚠️ Saldo akhir tidak seimbang' }}
            </span>
            <span class="text-gray-700 dark:text-gray-300">
                Saldo awal: <strong>{{ $fmt($totals['opening_debit']) }}</strong> &middot;
                Mutasi: <strong>{{ $fmt($totals['debit']) }}</strong> &middot;
                Saldo akhir: <strong>{{ $fmt($totals['closing_debit']) }}</strong>
                @if(! $totals['closing_balanced'])
                    &middot; Selisih: <strong>{{ $fmt($totals['closing_difference']) }}</strong>
                @endif
            </span>
        </div>
    </div>

    {{-- Lines --}}
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-3">Kode</th>
                        <th class="px-4 py-3">Nama Akun</th>
                        <th class="px-4 py-3">Pos</th>
                        <th class="px-4 py-3 text-right">Saldo Awal D</th>
                        <th class="px-4 py-3 text-right">Saldo Awal K</th>
                        <th class="px-4 py-3 text-right">Mutasi D</th>
                        <th class="px-4 py-3 text-right">Mutasi K</th>
                        <th class="px-4 py-3 text-right">Saldo Akhir D</th>
                        <th class="px-4 py-3 text-right">Saldo Akhir K</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse($rows as $row)
                    <tr class="text-gray-700 dark:text-gray-200">
                        <td class="px-4 py-2 whitespace-nowrap font-mono text-xs">{{ $row['code'] }}</td>
                        <td class="px-4 py-2">{{ $row['name'] }}</td>
                        <td class="px-4 py-2 whitespace-nowrap text-xs">{{ $row['pos'] }}</td>
                        <td class="px-4 py-2 text-right">{{ $row['opening_debit'] ? $fmt($row['opening_debit']) : '' }}</td>
                        <td class="px-4 py-2 text-right">{{ $row['opening_credit'] ? $fmt($row['opening_credit']) : '' }}</td>
                        <td class="px-4 py-2 text-right">{{ $row['debit'] ? $fmt($row['debit']) : '' }}</td>
                        <td class="px-4 py-2 text-right">{{ $row['credit'] ? $fmt($row['credit']) : '' }}</td>
                        <td class="px-4 py-2 text-right">{{ $row['closing_debit'] ? $fmt($row['closing_debit']) : '' }}</td>
                        <td class="px-4 py-2 text-right">{{ $row['closing_credit'] ? $fmt($row['closing_credit']) : '' }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="9" class="px-4 py-8 text-center text-gray-400">
                            Belum ada jurnal terposting pada periode ini. Jalankan posting jurnal untuk periode
                            {{ $report['period']['start'] }} &ndash; {{ $report['period']['end'] }}.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
                @if(count($rows) > 0)
                <tfoot class="bg-gray-50 dark:bg-gray-800">
                    <tr class="font-semibold text-gray-900 dark:text-white">
                        <td colspan="3" class="px-4 py-3 text-right">TOTAL</td>
                        <td class="px-4 py-3 text-right">{{ $fmt($totals['opening_debit']) }}</td>
                        <td class="px-4 py-3 text-right">{{ $fmt($totals['opening_credit']) }}</td>
                        <td class="px-4 py-3 text-right">{{ $fmt($totals['debit']) }}</td>
                        <td class="px-4 py-3 text-right">{{ $fmt($totals['credit']) }}</td>
                        <td class="px-4 py-3 text-right">{{ $fmt($totals['closing_debit']) }}</td>
                        <td class="px-4 py-3 text-right">{{ $fmt($totals['closing_credit']) }}</td>
                    </tr>
                </tfoot>
                @endif
            </table>
        </div>
    </div>
</div>
</x-filament-panels::page>

