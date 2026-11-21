<?php

namespace App\Services;

use App\Models\Payable;
use App\Models\PayablePayment;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * BALI-16 / BALI-17: the single place where a receivable or payable payment is
 * recorded. Every payment is its own row and the parent's paid_amount is always
 * recomputed from the ledger, so the header total and the payment history can
 * never drift apart.
 */
class PaymentLedger
{
    /**
     * @param  array{paid_on?: string|null, amount: float|int|string, method?: string|null, reference_no?: string|null, notes?: string|null}  $data
     */
    public function recordReceivable(Receivable $receivable, array $data, ?int $userId = null): ReceivablePayment
    {
        return DB::transaction(function () use ($receivable, $data, $userId): ReceivablePayment {
            /** @var Receivable $receivable */
            $receivable = Receivable::whereKey($receivable->getKey())->lockForUpdate()->firstOrFail();

            $amount = $this->guardAmount((float) $receivable->balance, $data['amount'] ?? 0);

            $payment = $receivable->payments()->create([
                'paid_on' => $data['paid_on'] ?? today()->toDateString(),
                'amount' => $amount,
                'method' => $data['method'] ?? 'cash',
                'reference_no' => $data['reference_no'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $receivable->syncPaidFromLedger();

            return $payment;
        });
    }

    /**
     * @param  array{paid_on?: string|null, amount: float|int|string, method?: string|null, reference_no?: string|null, notes?: string|null, delivery_order_id?: int|null}  $data
     */
    public function recordPayable(Payable $payable, array $data, ?int $userId = null): PayablePayment
    {
        return DB::transaction(function () use ($payable, $data, $userId): PayablePayment {
            /** @var Payable $payable */
            $payable = Payable::whereKey($payable->getKey())->lockForUpdate()->firstOrFail();

            $amount = $this->guardAmount((float) $payable->balance, $data['amount'] ?? 0);

            $payment = $payable->payments()->create([
                'delivery_order_id' => $data['delivery_order_id'] ?? $payable->delivery_order_id,
                'paid_on' => $data['paid_on'] ?? today()->toDateString(),
                'amount' => $amount,
                'method' => $data['method'] ?? 'cash',
                'reference_no' => $data['reference_no'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $payable->syncPaidFromLedger();

            return $payment;
        });
    }

    /**
     * BALI-17 compatibility: the mobile app still calls
     * POST /payables/{payable}/pay with the new *absolute* paid_amount target
     * (it prefills the balance). Record only the difference, so the old
     * "set the total" behaviour and the new ledger both stay correct.
     * Returns null when the target is already reached.
     */
    public function recordPayableTarget(Payable $payable, float $target, ?int $userId = null): ?PayablePayment
    {
        return DB::transaction(function () use ($payable, $target, $userId): ?PayablePayment {
            /** @var Payable $payable */
            $payable = Payable::whereKey($payable->getKey())->lockForUpdate()->firstOrFail();

            $delta = round($target - (float) $payable->paid_amount, 2);

            if ($delta <= 0) {
                return null;
            }

            return $this->recordPayable($payable, [
                'amount' => $delta,
                'method' => 'other',
                'notes' => 'Pembayaran via POST /payables/{id}/pay',
            ], $userId);
        });
    }

    public function reverseReceivablePayment(ReceivablePayment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            /** @var Receivable $receivable */
            $receivable = Receivable::whereKey($payment->receivable_id)->lockForUpdate()->firstOrFail();

            $payment->delete();

            $receivable->syncPaidFromLedger();
        });
    }

    public function reversePayablePayment(PayablePayment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            /** @var Payable $payable */
            $payable = Payable::whereKey($payment->payable_id)->lockForUpdate()->firstOrFail();

            $payment->delete();

            $payable->syncPaidFromLedger();
        });
    }

    /**
     * A payment must be positive and may never exceed the outstanding balance.
     */
    private function guardAmount(float $balance, float|int|string|null $amount): float
    {
        $amount = round((float) $amount, 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Jumlah pembayaran harus lebih besar dari 0.',
            ]);
        }

        if ($amount > $balance) {
            throw ValidationException::withMessages([
                'amount' => 'Jumlah pembayaran (Rp '.number_format($amount, 0, ',', '.')
                    .') melebihi sisa tagihan (Rp '.number_format($balance, 0, ',', '.').').',
            ]);
        }

        return $amount;
    }
}
