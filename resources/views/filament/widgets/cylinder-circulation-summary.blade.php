<x-filament-widgets::widget>
@php
    $matrix = $this->getMatrix();
    $rows   = $matrix['rows'];
    $types  = $matrix['types'];
    $totals = $matrix['totals'];
@endphp

<div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900 overflow-x-auto">
    <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-700">
        <h3 class="font-semibold text-gray-900 dark:text-white">🔄 Sirkulasi Tabung — Ringkasan per Cabang</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Saldo tabung aktif per cabang berdasarkan semua transaksi sirkulasi.</p>
    </div>
    @if(empty($rows))
    <div class="px-6 py-8 text-center text-sm text-gray-400">Belum ada data sirkulasi.</div>
    @else
    <table class="w-full text-sm">
        <thead class="bg-gray-50 dark:bg-gray-800">
            <tr>
                <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500 dark:text-gray-400 sticky left-0 bg-gray-50 dark:bg-gray-800">Cabang</th>
                @foreach($types as $type)
                <th class="px-3 py-3 text-center text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">
                    <span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800 dark:bg-blue-900 dark:text-blue-200">{{ $type }}</span>
                </th>
                @endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
            @foreach($rows as $row)
            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                <td class="px-4 py-3 font-medium text-gray-900 dark:text-white sticky left-0 bg-white dark:bg-gray-900 whitespace-nowrap">
                    {{ $row['branch']->name }}
                    <span class="block text-xs font-normal text-gray-400">{{ $row['branch']->city }}</span>
                </td>
                @foreach($types as $type)
                @php $qty = $row[$type]; @endphp
                <td class="px-3 py-3 text-center font-semibold {{ $qty > 0 ? 'text-green-600' : 'text-gray-300' }}">
                    {{ $qty > 0 ? number_format($qty) : '—' }}
                </td>
                @endforeach
            </tr>
            @endforeach
        </tbody>
        <tfoot class="border-t-2 border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-800">
            <tr>
                <td class="px-4 py-3 font-bold text-gray-900 dark:text-white sticky left-0 bg-gray-50 dark:bg-gray-800">Total</td>
                @foreach($types as $type)
                <td class="px-3 py-3 text-center font-bold text-green-700 dark:text-green-400">{{ number_format($totals[$type]) }}</td>
                @endforeach
            </tr>
        </tfoot>
    </table>
    @endif
</div>
</x-filament-widgets::widget>