<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\DeliveryOrder;
use App\Models\Invoice;
use App\Models\Payable;
use App\Models\Payment;
use App\Models\PaymentDetail;
use App\Models\Receivable;
use App\Support\JournalMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 19B (Option A): the single place where money is recorded.
 *
 * A payment is a *header* (one transfer: date, method, Kas/Bank, branch,
 * reference) plus one or more `payment_details` allocations. That is what makes
 * "one transfer settles three invoices" post as one journal entry instead of
 * three unrelated cash lines.
 *
 * Invariants enforced here, for every caller:
 *  - `payments.amount` === SUM(`payment_details.amount`) (checked after insert);
 *  - a detail points at exactly one invoice / receivable / payable;
 *  - no allocation exceeds its document's outstanding balance (grouped per
 *    document, so two lines for the same invoice cannot over-pay it);
 *  - every allocation of one payment belongs to the same branch;
 *  - invoices/receivables are money in, payables are money out — never mixed;
 *  - the header always names a Kas/Bank account (falls back on the method,
 *    exactly like JournalMapping does, so the journal never guesses).
 *
 * `recordInvoice()` / `recordPayable()` / `recordReceivable()` keep the old
 * per-document call sites working: they now create a one-line header and return
 * that `PaymentDetail`, whose delegated accessors still expose paid_on / method /
 * reference_no / cash_account_id.
 */
class PaymentLedger
{
    public function __construct(private readonly DocumentNumberService $documentNumbers) {}

    /**
     * Record one transfer allocated over one or more documents.
     *
     * @param  array{
     *     paid_on?: string|null,
     *     direction?: string|null,
     *     method?: string|null,
     *     cash_account_id?: int|null,
     *     reference_no?: string|null,
     *     notes?: string|null,
     *     details: array<int, array{invoice_id?: int|null, payable_id?: int|null, receivable_id?: int|null, delivery_order_id?: int|null, amount: float|int|string, notes?: string|null}>
     * }  $data
     */
    public function record(array $data, ?int $userId = null): Payment
    {
        return DB::transaction(function () use ($data, $userId): Payment {
            $details = $this->normaliseDetails($data['details'] ?? []);

            $targets = [];
            foreach ($details as $index => $detail) {
                $targets[$index] = $this->lockTarget($detail);
            }

            $details = $this->guardAllocations($details, $targets);

            $direction = $this->resolveDirection($details, $data['direction'] ?? null);
            $branchId = $this->resolveBranch($details, $targets);

            $method = (string) ($data['method'] ?? 'cash');
            $paidOn = (string) ($data['paid_on'] ?? today()->toDateString());
            $total = round(array_sum(array_column($details, 'amount')), 2);

            $payment = Payment::create([
                'payment_no' => $this->documentNumbers->next('pmt', (int) substr($paidOn, 0, 4)),
                'direction' => $direction,
                'paid_on' => $paidOn,
                'method' => $method,
                'cash_account_id' => $this->resolveCashAccountId($data['cash_account_id'] ?? null, $method),
                'branch_id' => $branchId,
                'amount' => $total,
                'reference_no' => $data['reference_no'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($details as $detail) {
                $payment->details()->create([
                    'invoice_id' => $detail['type'] === 'invoice' ? $detail['id'] : null,
                    'payable_id' => $detail['type'] === 'payable' ? $detail['id'] : null,
                    'receivable_id' => $detail['type'] === 'receivable' ? $detail['id'] : null,
                    'delivery_order_id' => $this->deliveryOrderId($detail, $targets[$detail['index']]),
                    'amount' => $detail['amount'],
                    'notes' => $detail['notes'] ?? $data['notes'] ?? null,
                ]);
            }

            $synced = [];
            foreach ($details as $detail) {
                if (isset($synced[$detail['key']])) {
                    continue;
                }

                $synced[$detail['key']] = true;
                $targets[$detail['index']]->syncPaidFromLedger();
            }

            $this->assertHeaderMatchesDetails($payment);

            return $payment->load('details.payment');
        });
    }

    /**
     * Phase 19A / T19A-03 compatibility: one invoice, one instalment.
     *
     * @param  array{paid_on?: string|null, amount: float|int|string, method?: string|null, cash_account_id?: int|null, reference_no?: string|null, notes?: string|null}  $data
     */
    public function recordInvoice(Invoice $invoice, array $data, ?int $userId = null): PaymentDetail
    {
        $payment = $this->record([
            'paid_on' => $data['paid_on'] ?? null,
            'method' => $data['method'] ?? null,
            'cash_account_id' => $data['cash_account_id'] ?? null,
            'reference_no' => $data['reference_no'] ?? null,
            'notes' => $data['notes'] ?? null,
            'details' => [[
                'invoice_id' => $invoice->getKey(),
                'amount' => $data['amount'] ?? 0,
                'notes' => $data['notes'] ?? null,
            ]],
        ], $userId);

        return $payment->details->firstOrFail();
    }

    /**
     * BALI-16 compatibility: one receivable, one instalment.
     *
     * @param  array{paid_on?: string|null, amount: float|int|string, method?: string|null, reference_no?: string|null, notes?: string|null}  $data
     */
    public function recordReceivable(Receivable $receivable, array $data, ?int $userId = null): PaymentDetail
    {
        $payment = $this->record([
            'paid_on' => $data['paid_on'] ?? null,
            'method' => $data['method'] ?? null,
            'reference_no' => $data['reference_no'] ?? null,
            'notes' => $data['notes'] ?? null,
            'details' => [[
                'receivable_id' => $receivable->getKey(),
                'amount' => $data['amount'] ?? 0,
                'notes' => $data['notes'] ?? null,
            ]],
        ], $userId);

        return $payment->details->firstOrFail();
    }

    /**
     * BALI-17 compatibility: one Transportir payable, one instalment.
     *
     * @param  array{paid_on?: string|null, amount: float|int|string, method?: string|null, cash_account_id?: int|null, reference_no?: string|null, notes?: string|null, delivery_order_id?: int|null}  $data
     */
    public function recordPayable(Payable $payable, array $data, ?int $userId = null): PaymentDetail
    {
        $payment = $this->record([
            'paid_on' => $data['paid_on'] ?? null,
            'method' => $data['method'] ?? null,
            'cash_account_id' => $data['cash_account_id'] ?? null,
            'reference_no' => $data['reference_no'] ?? null,
            'notes' => $data['notes'] ?? null,
            'details' => [[
                'payable_id' => $payable->getKey(),
                'delivery_order_id' => $data['delivery_order_id'] ?? null,
                'amount' => $data['amount'] ?? 0,
                'notes' => $data['notes'] ?? null,
            ]],
        ], $userId);

        return $payment->details->firstOrFail();
    }

    /**
     * BALI-17 compatibility: the mobile app still calls
     * POST /payables/{payable}/pay with the new *absolute* paid_amount target
     * (it prefills the balance). Record only the difference, so the old
     * "set the total" behaviour and the ledger both stay correct.
     * Returns null when the target is already reached.
     */
    public function recordPayableTarget(Payable $payable, float $target, ?int $userId = null): ?PaymentDetail
    {
        return DB::transaction(function () use ($payable, $target, $userId): ?PaymentDetail {
            /** @var Payable $locked */
            $locked = Payable::whereKey($payable->getKey())->lockForUpdate()->firstOrFail();

            $difference = round($target - (float) $locked->paid_amount, 2);

            if ($difference <= 0) {
                return null;
            }

            return $this->recordPayable($locked, ['amount' => $difference, 'method' => 'other'], $userId);
        });
    }

    /**
     * Void a whole transfer: every allocation disappears and each document is
     * re-synced, so a mis-keyed multi-document payment un-pays all of them.
     */
    public function reverse(Payment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            /** @var Payment|null $locked */
            $locked = Payment::whereKey($payment->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            $details = $locked->details()->get();

            $locked->delete();

            $this->syncTargets($details);

            $payment->unsetRelation('details');
        });
    }

    /**
     * Remove one allocation. When it is the only allocation of its header the
     * header is removed too (this is what the old per-document "void payment"
     * did); otherwise the header keeps the remaining allocations and its amount
     * is recomputed, preserving `amount = SUM(details)`.
     */
    public function reverseInvoicePayment(PaymentDetail $payment): void
    {
        $this->removeDetail($payment);
    }

    public function reversePayablePayment(PaymentDetail $payment): void
    {
        $this->removeDetail($payment);
    }

    public function reverseReceivablePayment(PaymentDetail $payment): void
    {
        $this->removeDetail($payment);
    }

    private function removeDetail(PaymentDetail $detail): void
    {
        DB::transaction(function () use ($detail): void {
            /** @var PaymentDetail|null $locked */
            $locked = PaymentDetail::whereKey($detail->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            /** @var Payment|null $parent */
            $parent = Payment::whereKey($locked->payment_id)->lockForUpdate()->first();
            $targets = collect([$locked]);

            if ($parent !== null && $parent->details()->count() === 1) {
                $targets = $parent->details()->get();
                $parent->delete();
            } else {
                $locked->delete();
            }

            if ($parent !== null && $parent->exists) {
                $parent->amount = round((float) $parent->details()->sum('amount'), 2);
                $parent->saveQuietly();
            }

            $this->syncTargets($targets);
        });
    }

    /**
     * Re-derive paid_amount / status of every document touched by a payment.
     *
     * @param  iterable<int, PaymentDetail>  $details
     */
    private function syncTargets(iterable $details): void
    {
        $synced = [];

        foreach ($details as $detail) {
            [$model, $id] = $this->targetModelAndId($detail);

            if ($model === null || isset($synced[$model.':'.$id])) {
                continue;
            }

            $synced[$model.':'.$id] = true;

            $model::whereKey($id)->lockForUpdate()->first()?->syncPaidFromLedger();
        }
    }

    /**
     * @return array{0: class-string|null, 1: int|null}
     */
    private function targetModelAndId(PaymentDetail $detail): array
    {
        if ($detail->invoice_id !== null) {
            return [Invoice::class, (int) $detail->invoice_id];
        }

        if ($detail->payable_id !== null) {
            return [Payable::class, (int) $detail->payable_id];
        }

        if ($detail->receivable_id !== null) {
            return [Receivable::class, (int) $detail->receivable_id];
        }

        return [null, null];
    }

    /**
     * Validate the allocation rows and keep one internal shape for the rest of
     * the method: index, document type/id, amount, notes, LO.
     *
     * @return array<int, array{index: int, key: string, type: string, id: int, amount: float|int|string, notes: string|null, delivery_order_id: int|null}>
     */
    private function normaliseDetails(array $details): array
    {
        if ($details === []) {
            throw ValidationException::withMessages([
                'details' => 'Pembayaran harus dialokasikan ke minimal satu dokumen.',
            ]);
        }

        $normalised = [];

        foreach (array_values($details) as $index => $detail) {
            $targets = array_filter([
                'invoice' => (int) ($detail['invoice_id'] ?? 0),
                'payable' => (int) ($detail['payable_id'] ?? 0),
                'receivable' => (int) ($detail['receivable_id'] ?? 0),
            ], fn (int $id): bool => $id > 0);

            if (count($targets) !== 1) {
                throw ValidationException::withMessages([
                    'details.'.$index => 'Setiap baris pembayaran harus menunjuk tepat satu dokumen (invoice, piutang, atau payable).',
                ]);
            }

            $type = (string) array_key_first($targets);

            $normalised[] = [
                'index' => $index,
                'key' => $type.':'.$targets[$type],
                'type' => $type,
                'id' => (int) $targets[$type],
                'amount' => $detail['amount'] ?? 0,
                'notes' => $detail['notes'] ?? null,
                'delivery_order_id' => isset($detail['delivery_order_id'])
                    ? (int) $detail['delivery_order_id']
                    : null,
            ];
        }

        return $normalised;
    }

    private function lockTarget(array $detail): Invoice|Payable|Receivable
    {
        return match ($detail['type']) {
            'invoice' => Invoice::whereKey($detail['id'])->lockForUpdate()->firstOrFail(),
            'payable' => Payable::whereKey($detail['id'])->lockForUpdate()->firstOrFail(),
            'receivable' => Receivable::whereKey($detail['id'])->lockForUpdate()->firstOrFail(),
        };
    }

    /**
     * Round every row, require it to be positive, then compare the sum *per
     * document* against that document's outstanding balance.
     *
     * @param  array<int, array<string, mixed>>  $details
     * @param  array<int, Invoice|Payable|Receivable>  $targets
     * @return array<int, array<string, mixed>>
     */
    private function guardAllocations(array $details, array $targets): array
    {
        $grouped = [];

        foreach ($details as $index => $detail) {
            $details[$index]['amount'] = $this->normaliseAmount($detail['amount'], 'details.'.$index.'.amount');

            $grouped[$detail['key']] = [
                'index' => $grouped[$detail['key']]['index'] ?? $index,
                'amount' => ($grouped[$detail['key']]['amount'] ?? 0) + $details[$index]['amount'],
            ];
        }

        foreach ($grouped as $group) {
            $this->guardAmount(
                (float) $targets[$group['index']]->balance,
                $group['amount'],
                'details.'.$group['index'].'.amount',
            );
        }

        return $details;
    }

    private function normaliseAmount(float|int|string|null $amount, string $key): float
    {
        $amount = round((float) $amount, 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                $key => 'Jumlah pembayaran harus lebih besar dari 0.',
            ]);
        }

        return $amount;
    }

    /** A payment may never exceed the outstanding balance of its document. */
    private function guardAmount(float $balance, float $amount, string $key = 'amount'): void
    {
        if ($amount > $balance) {
            throw ValidationException::withMessages([
                $key => 'Jumlah pembayaran (Rp '.number_format($amount, 0, ',', '.')
                    .') melebihi sisa tagihan (Rp '.number_format($balance, 0, ',', '.').').',
            ]);
        }
    }

    /**
     * Invoices/receivables are money in, payables are money out. Mixing them in
     * one header would post a cash line against itself, so it is rejected.
     */
    private function resolveDirection(array $details, ?string $requested): string
    {
        $direction = $this->directionOf($details[0]);

        foreach ($details as $detail) {
            if ($this->directionOf($detail) !== $direction) {
                throw ValidationException::withMessages([
                    'details' => 'Satu pembayaran tidak boleh mencampur dokumen masuk (invoice/piutang) dengan pembayaran keluar (payable).',
                ]);
            }
        }

        if ($requested !== null && $requested !== '' && $requested !== $direction) {
            throw ValidationException::withMessages([
                'direction' => 'Arah pembayaran tidak sesuai dengan dokumen yang dialokasikan.',
            ]);
        }

        return $direction;
    }

    private function directionOf(array $detail): string
    {
        return $detail['type'] === 'payable' ? Payment::DIRECTION_OUT : Payment::DIRECTION_IN;
    }

    /**
     * One payment = one branch. Documents that carry no branch (a payable whose
     * LO is gone) are ignored rather than blocking the payment.
     */
    private function resolveBranch(array $details, array $targets): ?int
    {
        $branchIds = [];

        foreach ($details as $detail) {
            $branchId = $this->branchIdForTarget($detail, $targets[$detail['index']]);

            if ($branchId !== null) {
                $branchIds[$branchId] = true;
            }
        }

        if (count($branchIds) > 1) {
            throw ValidationException::withMessages([
                'details' => 'Semua dokumen dalam satu pembayaran harus berada di cabang yang sama.',
            ]);
        }

        return $branchIds === [] ? null : (int) array_key_first($branchIds);
    }

    private function branchIdForTarget(array $detail, Invoice|Payable|Receivable $target): ?int
    {
        if ($target instanceof Payable) {
            $deliveryOrderId = $this->deliveryOrderId($detail, $target);

            if (! $deliveryOrderId) {
                return null;
            }

            $deliveryOrder = DeliveryOrder::query()->find($deliveryOrderId);

            return $deliveryOrder?->origin_branch_id ?? $deliveryOrder?->destination_branch_id;
        }

        return $target->branch_id !== null ? (int) $target->branch_id : null;
    }

    private function deliveryOrderId(array $detail, Invoice|Payable|Receivable $target): ?int
    {
        if (! $target instanceof Payable) {
            return $detail['delivery_order_id'];
        }

        return $detail['delivery_order_id']
            ?? ($target->delivery_order_id !== null ? (int) $target->delivery_order_id : null);
    }

    /**
     * The journal must always know which Kas/Bank the money moved through. When
     * the caller does not name one, fall back on the method exactly like
     * JournalMapping does (transfer/giro => Bank 1-112, else Kas 1-111), and use
     * the first cash account only if that code is missing from the COA.
     */
    public function resolveCashAccountId(?int $cashAccountId, ?string $method): int
    {
        if ($cashAccountId !== null && $cashAccountId > 0) {
            return $cashAccountId;
        }

        $preferred = in_array($method, ['transfer', 'giro'], true)
            ? JournalMapping::ACCOUNT_BANK
            : JournalMapping::ACCOUNT_CASH;

        $account = ChartOfAccount::cashAccounts()->where('code', $preferred)->first()
            ?? ChartOfAccount::cashAccounts()->orderBy('code')->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                'cash_account_id' => 'Akun Kas/Bank belum tersedia; pilih akun Kas/Bank terlebih dahulu.',
            ]);
        }

        return (int) $account->id;
    }

    /** Belt and braces: the header total must equal what the details allocate. */
    private function assertHeaderMatchesDetails(Payment $payment): void
    {
        $allocated = round((float) $payment->details()->sum('amount'), 2);

        if (abs($allocated - (float) $payment->amount) > 0.005) {
            throw ValidationException::withMessages([
                'amount' => 'Total pembayaran (Rp '.number_format((float) $payment->amount, 0, ',', '.')
                    .') tidak sama dengan alokasinya (Rp '.number_format($allocated, 0, ',', '.').').',
            ]);
        }
    }
}



