{{--
    BALI-16 / BALI-17: payment history shown in the "Payments" modal action of the
    Receivables and Payables tables (and on their Edit pages).

    Expects:
      $payments - collection of ReceivablePayment / PayablePayment rows
--}}
@php
    $fmt = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
    $total = $payments->sum(fn ($p) => (float) $p->amount);
@endphp

<div class="space-y-3">
    @if ($payments->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Belum ada pembayaran yang dicatat.
        </p>
    @else
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                    <tr>
                        <th class="px-3 py-2 text-left">Tanggal</th>
                        <th class="px-3 py-2 text-right">Jumlah</th>
                        <th class="px-3 py-2 text-left">Metode</th>
                        <th class="px-3 py-2 text-left">Referensi</th>
                        <th class="px-3 py-2 text-left">Catatan</th>
                        <th class="px-3 py-2 text-left">Dicatat oleh</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($payments as $payment)
                        <tr class="text-gray-700 dark:text-gray-200">
                            <td class="px-3 py-2 whitespace-nowrap">{{ $payment->paid_on?->format('d M Y') }}</td>
                            <td class="px-3 py-2 text-right font-semibold text-green-600 dark:text-green-400">
                                {{ $fmt($payment->amount) }}
                            </td>
                            <td class="px-3 py-2">{{ $payment->method_label }}</td>
                            <td class="px-3 py-2">{{ $payment->reference_no ?: '-' }}</td>
                            <td class="px-3 py-2">{{ $payment->notes ?: '-' }}</td>
                            <td class="px-3 py-2">{{ $payment->createdBy?->name ?? '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <td class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            Total ({{ $payments->count() }} pembayaran)
                        </td>
                        <td class="px-3 py-2 text-right font-bold text-gray-900 dark:text-white">{{ $fmt($total) }}</td>
                        <td class="px-3 py-2" colspan="4"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
