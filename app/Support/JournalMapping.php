<?php

namespace App\Support;

use App\Models\ChartOfAccount;
use App\Models\DeliveryOrder;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\LpgPrice;
use App\Models\OpeningBalance;
use App\Models\OperationalCost;
use App\Models\Payment;
use App\Models\PaymentDetail;
use App\Models\StockClose;

/**
 * Phase 19A / T19A-07: map the operational tables onto the merged chart of
 * accounts so a Jurnal Umum can be produced without GL posting tables (Option C).
 *
 * Every source row becomes balanced debit/credit pairs:
 *
 *  Invoice issued        DR 1-121 Piutang Dagang            CR 4-11x Penjualan
 *                        DR 1-121 (expedition fee)          CR 5-132 (fee billed)
 *  Invoice redeemed      DR 5-11x HPP                       CR 1-13x Persediaan
 *  Pertamina receipt     DR 1-13x Persediaan                CR 2-111 Hutang Usaha
 *  Stock opname          DR/CR 5-131 Selisih Persediaan     CR/DR 1-13x Persediaan
 *  Transportir cost      DR 5-132                           CR 2-111 Hutang Usaha
 *  Invoice payment       DR 1-11x Kas/Bank                  CR 1-121 Piutang Dagang
 *  Operational cost      DR 5-12x/5-13x                     CR 1-11x Kas/Bank
 *  Opening balance       DR (saldo normal D)                CR (saldo normal K)
 *
 * HPP deliberately excludes the transportir rate here (see
 * docs/lapkeu-gap-analysis.md §4 D1): the client's workbook defines HPP as the
 * Pertamina purchase price alone, with transport on its own expense line.
 *
 * Decision P2 (2026-11-23): HPP clears the stock account (`1-131` / `1-132` /
 * `1-133`) instead of `2-111 Hutang Usaha`, so inventory now moves through the
 * ledger. The client only trades 5.5 kg / 12 kg / 50 kg cylinders - LPG 3 kg is
 * personal use and has no stock account (decision P2 on 2026-11-23), so a 3 kg
 * HPP keeps clearing straight to `2-111`.
 *
 * Decision P3 (2026-11-23): the receipt half of T19B-10 is booked from the
 * Pertamina supply delivery order (`document_type = do`, `counterparty_type =
 * pertamina`, `status = delivered`), valued at the Pertamina purchase price in
 * force on the received date - the same price the sale's HPP credits back out.
 * The app holds no purchase invoice, so the other side of that entry is the
 * trade payable (`2-111`): any difference between the receipt value and what
 * Pertamina eventually bills stays in hutang/piutang instead of being booked to
 * a variance account.
 *
 * Decision P4 (2026-11-23): a `verified` stock close is compared with
 * `App\Support\StockLedger` (the `stock_mutations` ledger) and the difference is
 * booked to `5-131 Selisih Persediaan Tabung` against the stock account.
 *
 * Phase 19B / T19B-02: the same mapping now also feeds the persisted journal
 * (`App\Services\JournalPostingService`). Every row therefore carries the
 * `source_type` / `source_id` of the operational record it came from, so the
 * posting service can post, compare and void entries idempotently.
 *
 * Rows whose invoice targets another branch (`reference_type = branch`) are
 * flagged `is_intercompany`: that is a Pusat ↔ Cabang transfer, which the
 * consolidated P&L must eliminate (decision D4).
 */
class JournalMapping
{
    /** Revenue account per cylinder type. */
    public const SALES_ACCOUNTS = [
        '3kg' => '4-114',
        '5.5kg' => '4-111',
        '12kg' => '4-112',
        '50kg' => '4-113',
    ];

    /** HPP account per cylinder type. */
    public const HPP_ACCOUNTS = [
        '3kg' => '5-114',
        '5.5kg' => '5-111',
        '12kg' => '5-112',
        '50kg' => '5-113',
    ];

    /**
     * Stock account per cylinder type the business actually trades (P2).
     *
     * Only the three business types have an inventory account: the client does
     * not sell 3 kg cylinders (personal use), so that type is absent here and
     * its HPP clears straight to `2-111` (see the class docblock).
     */
    public const INVENTORY_ACCOUNTS = [
        '5.5kg' => '1-131',
        '12kg' => '1-132',
        '50kg' => '1-133',
    ];

    /** Fallback expense account per legacy `operational_costs.cost_category`. */
    public const CATEGORY_ACCOUNTS = [
        'fuel' => '5-124',
        'salary' => '5-121',
        'logistics' => '5-123',
        'levy' => '5-130',
        'other' => '5-128',
    ];

    public const ACCOUNT_RECEIVABLE = '1-121';

    public const ACCOUNT_PAYABLE = '2-111';

    public const ACCOUNT_TRANSPORT = '5-132';

    /** Expense account of a stock-opname difference (T19B-14). */
    public const ACCOUNT_STOCK_DIFFERENCE = '5-131';

    public const ACCOUNT_CASH = '1-111';

    public const ACCOUNT_BANK = '1-112';

    /** Equity account that absorbs an opening trial balance that does not balance. */
    public const ACCOUNT_RETAINED_EARNINGS = '3-112';

    /**
     * Stock account that absorbs the HPP of a cylinder type, or null when the
     * business does not stock that type (decision P2, 2026-11-23).
     */
    public static function inventoryAccountFor(string $cylinderType): ?string
    {
        return self::INVENTORY_ACCOUNTS[$cylinderType] ?? null;
    }

    /**
     * Build every journal line of a period.
     *
     * @return array<int, array{date: string, ref: string, description: string, account_code: string, account_name: string, branch: string, source: string, debit: float, credit: float, branch_id: int|null, source_type: string, source_id: int|null, is_intercompany: bool}>
     */
    public static function build(?int $branchId = null, ?string $startDate = null, ?string $endDate = null): array
    {
        $startDate = $startDate ?: now()->startOfMonth()->toDateString();
        $endDate = $endDate ?: now()->toDateString();
        $names = ChartOfAccount::query()->pluck('name', 'code')->all();
        $rows = [];

        /**
         * @param  array{branch_id: int|null, source_type: string, source_id: int|null, is_intercompany: bool}  $meta
         */
        $push = static function (string $date, string $ref, string $description, string $code, string $branch, string $source, float $debit, float $credit, array $meta) use (&$rows, $names): void {
            if (round($debit, 2) === 0.0 && round($credit, 2) === 0.0) {
                return;
            }

            $rows[] = [
                'date' => $date,
                'ref' => $ref,
                'description' => $description,
                'account_code' => $code,
                'account_name' => $names[$code] ?? $code,
                'branch' => $branch,
                'source' => $source,
                'debit' => round($debit, 2),
                'credit' => round($credit, 2),
                'branch_id' => $meta['branch_id'],
                'source_type' => $meta['source_type'],
                'source_id' => $meta['source_id'],
                'is_intercompany' => $meta['is_intercompany'],
            ];
        };

        // An invoice belongs to the period when it was issued: its payment
        // entries come from `payments` below, one per transfer.
        $invoices = Invoice::query()
            ->with(['branch:id,name', 'lpgPrice:id,purchase_price'])
            ->whereDate('issue_date', '>=', $startDate)
            ->whereDate('issue_date', '<=', $endDate)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->orderBy('issue_date')
            ->orderBy('id')
            ->get();

        $inRange = static fn (string $date): bool => $date >= $startDate && $date <= $endDate;

        foreach ($invoices as $invoice) {
            $branch = (string) ($invoice->branch?->name ?? '-');
            $ref = (string) $invoice->invoice_number;
            $type = (string) $invoice->cylinder_type;
            $qty = (float) $invoice->quantity;
            $issueDate = $invoice->issue_date->toDateString();

            // D4: an invoice addressed to a branch is a Pusat ↔ Cabang transfer,
            // not external revenue of the group.
            $meta = [
                'branch_id' => (int) $invoice->branch_id,
                'source_type' => 'invoice',
                'source_id' => (int) $invoice->id,
                'is_intercompany' => $invoice->reference_type === 'branch',
            ];

            // --- Penjualan -------------------------------------------------
            $revenue = round((float) $invoice->unit_price * $qty, 2);

            if ($revenue <= 0) {
                $revenue = round((float) $invoice->total_amount - (float) $invoice->expedition_fee_amount, 2);
            }

            $fee = round((float) $invoice->expedition_fee_amount, 2);
            $salesAccount = self::SALES_ACCOUNTS[$type] ?? '4-114';

            if ($inRange($issueDate)) {
                $push($issueDate, $ref, "Penjualan {$type} ke {$invoice->reference_name}", $salesAccount, $branch, JournalEntry::EVENT_SALES, 0, $revenue, $meta);
                $push($issueDate, $ref, "Tagihan {$type} ke {$invoice->reference_name}", self::ACCOUNT_RECEIVABLE, $branch, JournalEntry::EVENT_SALES, $revenue, 0, $meta);

                if ($fee > 0) {
                    $push($issueDate, $ref, 'Ongkos transportir dibebankan ke pembeli', self::ACCOUNT_RECEIVABLE, $branch, JournalEntry::EVENT_SALES, $fee, 0, $meta);
                    $push($issueDate, $ref, 'Pemulihan biaya transportir', self::ACCOUNT_TRANSPORT, $branch, JournalEntry::EVENT_SALES, 0, $fee, $meta);
                }
            }

            // --- HPP (Pertamina purchase price only) ------------------------
            $purchasePrice = (float) ($invoice->lpgPrice?->purchase_price
                ?? LpgPrice::priceAtDate($type, $issueDate, $invoice->branch_id)?->purchase_price
                ?? 0);
            $hpp = round($purchasePrice * $qty, 2);
            $receivedOn = ($invoice->redeemed_on ?? $invoice->issue_date)->toDateString();

            if ($hpp > 0 && $inRange($receivedOn)) {
                $hppAccount = self::HPP_ACCOUNTS[$type] ?? '5-114';
                $stockAccount = self::inventoryAccountFor($type);
                $push($receivedOn, $ref, "HPP {$type} dari Pertamina", $hppAccount, $branch, JournalEntry::EVENT_HPP, $hpp, 0, $meta);

                if ($stockAccount !== null) {
                    // P2: the stock leaves the warehouse, not the payable.
                    $push($receivedOn, $ref, "Persediaan {$type} keluar untuk penjualan", $stockAccount, $branch, JournalEntry::EVENT_HPP, 0, $hpp, $meta);
                } else {
                    // 3 kg is not a business cylinder: no stock account, so the
                    // HPP clears straight to the Pertamina payable.
                    $push($receivedOn, $ref, "Hutang pembelian {$type} ke Pertamina", self::ACCOUNT_PAYABLE, $branch, JournalEntry::EVENT_HPP, 0, $hpp, $meta);
                }
            }

            // --- Transportir cost (incurred; recovered when billed) ---------
            $transport = round((float) $invoice->transportir_rate_at_sale * $qty, 2);

            if ($transport > 0 && $inRange($issueDate)) {
                $push($issueDate, $ref, 'Biaya transportir', self::ACCOUNT_TRANSPORT, $branch, JournalEntry::EVENT_TRANSPORT, $transport, 0, $meta);
                $push($issueDate, $ref, 'Hutang transportir', self::ACCOUNT_PAYABLE, $branch, JournalEntry::EVENT_TRANSPORT, 0, $transport, $meta);
            }

            // --- Payments --------------------------------------------------
            // Phase 19B: a transfer is a header allocated over documents, so its
            // journal lines are emitted once per transfer below, not once per
            // invoice here.
        }

        // --- Customer receipts (Phase 19B) ------------------------------------
        // One journal entry per transfer: the Kas/Bank line carries the header
        // amount and the receivable is cleared per allocated invoice. That is
        // what makes "one transfer settles three invoices" reconcile with the
        // bank statement instead of showing three unrelated cash lines.
        //
        // Receivable (`BALI-16`) settlements are not posted: nothing in the
        // ledger recognises those receivables yet, so clearing 1-121 would drive
        // the control account negative. The cash itself is still recorded in
        // `payments` and will post once receivable recognition lands.
        $receipts = Payment::query()
            ->with(['branch:id,name', 'cashAccount:id,code', 'details.invoice'])
            ->where('direction', Payment::DIRECTION_IN)
            ->whereDate('paid_on', '>=', $startDate)
            ->whereDate('paid_on', '<=', $endDate)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereHas('details.invoice')
            ->orderBy('paid_on')
            ->orderBy('id')
            ->get();

        foreach ($receipts as $payment) {
            $details = $payment->details->filter(
                fn (PaymentDetail $detail): bool => $detail->invoice !== null && (float) $detail->amount > 0
            );

            if ($details->isEmpty()) {
                continue;
            }

            $date = $payment->paid_on->toDateString();

            if (! $inRange($date)) {
                continue;
            }

            $cashCode = $payment->cashAccount?->code
                ?: (($payment->method === 'transfer' || $payment->method === 'giro')
                    ? self::ACCOUNT_BANK
                    : self::ACCOUNT_CASH);

            $receiptBranch = (string) ($payment->branch?->name ?? '-');
            $receiptRef = (string) ($payment->payment_no ?: 'PM-'.$payment->id);

            $receiptMeta = [
                'branch_id' => $payment->branch_id !== null ? (int) $payment->branch_id : null,
                'source_type' => 'payment',
                'source_id' => (int) $payment->id,
                // A receipt from a branch customer is the other half of the
                // Pusat -> Cabang transfer and is eliminated like the sale (D4).
                'is_intercompany' => $details->contains(
                    fn (PaymentDetail $detail): bool => $detail->invoice?->reference_type === 'branch'
                ),
            ];

            // Only the allocated (postable) part of the transfer enters the
            // journal: a header may also carry receivable legs, which are not
            // recognised yet (see above). Using the header amount here would
            // unbalance the entry and abort the whole period sync.
            $posted = round((float) $details->sum(fn (PaymentDetail $detail): float => (float) $detail->amount), 2);

            $push($date, $receiptRef, 'Penerimaan pembayaran '.$payment->method, $cashCode, $receiptBranch, JournalEntry::EVENT_PAYMENT, $posted, 0, $receiptMeta);

            foreach ($details as $detail) {
                $push(
                    $date,
                    $receiptRef,
                    'Pelunasan piutang '.$detail->invoice->reference_name,
                    self::ACCOUNT_RECEIVABLE,
                    $receiptBranch,
                    JournalEntry::EVENT_PAYMENT,
                    0,
                    (float) $detail->amount,
                    $receiptMeta,
                );
            }
        }

        // --- Payable settlements (Phase 19B) ----------------------------------
        // The transport cost of a sale already credited 2-111 (EVENT_TRANSPORT)
        // and a Pertamina receipt credits it too (EVENT_RECEIPT), so paying a
        // payable debits 2-111 against Kas/Bank - again once per transfer, so the
        // bank line matches the statement.
        $settlements = Payment::query()
            ->with(['branch:id,name', 'cashAccount:id,code', 'details.payable'])
            ->where('direction', Payment::DIRECTION_OUT)
            ->whereDate('paid_on', '>=', $startDate)
            ->whereDate('paid_on', '<=', $endDate)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereHas('details.payable')
            ->orderBy('paid_on')
            ->orderBy('id')
            ->get();

        foreach ($settlements as $payment) {
            $details = $payment->details->filter(
                fn (PaymentDetail $detail): bool => $detail->payable !== null && (float) $detail->amount > 0
            );

            if ($details->isEmpty()) {
                continue;
            }

            $date = $payment->paid_on->toDateString();

            if (! $inRange($date)) {
                continue;
            }

            $cashCode = $payment->cashAccount?->code
                ?: (($payment->method === 'transfer' || $payment->method === 'giro')
                    ? self::ACCOUNT_BANK
                    : self::ACCOUNT_CASH);

            $settlementBranch = (string) ($payment->branch?->name ?? '-');
            $settlementRef = (string) ($payment->payment_no ?: 'PM-'.$payment->id);

            $settlementMeta = [
                'branch_id' => $payment->branch_id !== null ? (int) $payment->branch_id : null,
                'source_type' => 'payment',
                'source_id' => (int) $payment->id,
                'is_intercompany' => false,
            ];

            foreach ($details as $detail) {
                $push(
                    $date,
                    $settlementRef,
                    'Pelunasan hutang '.($detail->payable->invoice_number ?: 'AP#'.$detail->payable->id),
                    self::ACCOUNT_PAYABLE,
                    $settlementBranch,
                    JournalEntry::EVENT_SETTLEMENT,
                    (float) $detail->amount,
                    0,
                    $settlementMeta,
                );
            }

            // Cash leg = the payable allocations only, so a header that also
            // carries an LO advance (no payable yet) stays balanced.
            $settled = round((float) $details->sum(fn (PaymentDetail $detail): float => (float) $detail->amount), 2);

            $push(
                $date,
                $settlementRef,
                'Pembayaran hutang '.$payment->method,
                $cashCode,
                $settlementBranch,
                JournalEntry::EVENT_SETTLEMENT,
                0,
                $settled,
                $settlementMeta,
            );
        }

        // --- Operational costs ----------------------------------------------
        $costs = OperationalCost::query()
            ->with(['branch:id,name', 'chartOfAccount:id,code', 'cashAccount:id,code'])
            ->whereDate('cost_date', '>=', $startDate)
            ->whereDate('cost_date', '<=', $endDate)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->orderBy('cost_date')
            ->orderBy('id')
            ->get();

        foreach ($costs as $cost) {
            $branch = (string) ($cost->branch?->name ?? '-');
            $ref = $cost->reference_no ?: 'BIAYA-'.$cost->id;
            $date = $cost->cost_date->toDateString();
            $amount = round((float) $cost->amount, 2);
            $expenseCode = $cost->chartOfAccount?->code
                ?? self::CATEGORY_ACCOUNTS[(string) $cost->cost_category]
                ?? '5-128';
            $cashCode = $cost->cashAccount?->code
                ?: (($cost->payment_method === 'transfer' || $cost->payment_method === 'giro')
                    ? self::ACCOUNT_BANK
                    : self::ACCOUNT_CASH);

            $meta = [
                'branch_id' => (int) $cost->branch_id,
                'source_type' => 'operational_cost',
                'source_id' => (int) $cost->id,
                'is_intercompany' => false,
            ];

            $push($date, $ref, (string) $cost->description, $expenseCode, $branch, JournalEntry::EVENT_EXPENSE, $amount, 0, $meta);
            $push($date, $ref, 'Pembayaran '.($cost->payment_method ?: 'cash'), $cashCode, $branch, JournalEntry::EVENT_EXPENSE, 0, $amount, $meta);
        }

        // --- Pertamina purchase receipts (T19B-10 receipt half, P3) ----------
        // The supply delivery order is the only authoritative "this much stock
        // entered the company" figure the app holds: it is created from the SO
        // to Pertamina, carries `quantity_received` and, unlike the internal PO
        // movements, has Pertamina as counterparty. Stock is valued at the
        // Pertamina purchase price in force on the received date - the same
        // price the sale's HPP credits back out of the stock account - and the
        // other side of the entry is the trade payable.
        $receipts = DeliveryOrder::query()
            ->with('originBranch:id,name')
            ->where('document_type', 'do')
            ->where('counterparty_type', 'pertamina')
            ->where('status', 'delivered')
            ->whereNotNull('received_date')
            ->whereNotNull('quantity_received')
            ->whereDate('received_date', '>=', $startDate)
            ->whereDate('received_date', '<=', $endDate)
            ->when($branchId, fn ($query) => $query->where('origin_branch_id', $branchId))
            ->orderBy('received_date')
            ->orderBy('id')
            ->get();

        foreach ($receipts as $receipt) {
            $type = (string) $receipt->cylinder_type;
            $stockAccount = self::inventoryAccountFor($type);

            if ($stockAccount === null) {
                // 3 kg is personal use, not traded (P2): no stock account.
                continue;
            }

            $date = $receipt->received_date->toDateString();
            $qty = (float) $receipt->quantity_received;
            $price = (float) (LpgPrice::priceAtDate($type, $date, $receipt->origin_branch_id)?->purchase_price ?? 0);
            $value = round($price * $qty, 2);

            if ($value <= 0) {
                continue;
            }

            $branch = (string) ($receipt->originBranch?->name ?? '-');
            $ref = (string) $receipt->do_number;
            $meta = [
                'branch_id' => (int) $receipt->origin_branch_id,
                'source_type' => 'delivery_order',
                'source_id' => (int) $receipt->id,
                'is_intercompany' => false,
            ];

            $push($date, $ref, "Penerimaan {$type} dari Pertamina", $stockAccount, $branch, JournalEntry::EVENT_RECEIPT, $value, 0.0, $meta);
            $push($date, $ref, "Hutang pembelian {$type} ke Pertamina", self::ACCOUNT_PAYABLE, $branch, JournalEntry::EVENT_RECEIPT, 0.0, $value, $meta);
        }

        // --- Stock opname (T19B-14, P4) ---------------------------------------
        // Only a `verified` close is a final count: a draft or a submitted sheet
        // could still change, and posting it would book a difference twice.
        // The system quantity comes from the mutation ledger (StockLedger), so
        // a shortage is an expense and a surplus is a negative expense of the
        // same `5-131` account.
        $closes = StockClose::query()
            ->with('branch:id,name')
            ->where('status', 'verified')
            ->whereDate('close_date', '>=', $startDate)
            ->whereDate('close_date', '<=', $endDate)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->orderBy('close_date')
            ->orderBy('id')
            ->get();

        foreach ($closes as $close) {
            $type = (string) $close->cylinder_type;
            $stockAccount = self::inventoryAccountFor($type);

            if ($stockAccount === null) {
                continue;
            }

            $date = $close->close_date->toDateString();
            $difference = (int) $close->qty_full - StockLedger::quantityOn((int) $close->branch_id, $type, $date);

            if ($difference === 0) {
                continue;
            }

            $price = (float) (LpgPrice::priceAtDate($type, $date, $close->branch_id)?->purchase_price ?? 0);
            $value = round($price * abs($difference), 2);

            if ($value <= 0) {
                continue;
            }

            $branch = (string) ($close->branch?->name ?? '-');
            $ref = 'OPNAME-'.$date;
            $meta = [
                'branch_id' => (int) $close->branch_id,
                'source_type' => 'stock_close',
                'source_id' => (int) $close->id,
                'is_intercompany' => false,
            ];

            if ($difference < 0) {
                $push($date, $ref, "Selisih kurang persediaan {$type} (opname)", self::ACCOUNT_STOCK_DIFFERENCE, $branch, JournalEntry::EVENT_OPNAME, $value, 0.0, $meta);
                $push($date, $ref, "Persediaan {$type} berkurang menurut opname", $stockAccount, $branch, JournalEntry::EVENT_OPNAME, 0.0, $value, $meta);
            } else {
                $push($date, $ref, "Persediaan {$type} bertambah menurut opname", $stockAccount, $branch, JournalEntry::EVENT_OPNAME, $value, 0.0, $meta);
                $push($date, $ref, "Selisih lebih persediaan {$type} (opname)", self::ACCOUNT_STOCK_DIFFERENCE, $branch, JournalEntry::EVENT_OPNAME, 0.0, $value, $meta);
            }
        }

        // --- Opening balances (T19B-12) --------------------------------------
        // The balances the client carried over from their previous book. One
        // entry per branch *per as_of_date*; its source identity is the smallest
        // `opening_balances.id` of that batch so re-syncing stays idempotent and
        // a deleted document voids its entry like any other source (T19B-02).
        $openings = OpeningBalance::query()
            ->with(['branch:id,name', 'account:id,code,saldo_normal'])
            ->whereDate('as_of_date', '>=', $startDate)
            ->whereDate('as_of_date', '<=', $endDate)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->orderBy('as_of_date')
            ->orderBy('id')
            ->get();

        foreach ($openings->groupBy(fn (OpeningBalance $row): string => $row->branch_id.'|'.$row->as_of_date->toDateString()) as $batch) {
            $first = $batch->first();
            $date = $first->as_of_date->toDateString();
            $branch = (string) ($first->branch?->name ?? '-');
            $meta = [
                'branch_id' => (int) $first->branch_id,
                'source_type' => 'opening_balance',
                'source_id' => (int) $batch->min('id'),
                'is_intercompany' => false,
            ];

            $balance = 0.0;

            foreach ($batch as $row) {
                $amount = round((float) $row->amount, 2);

                if ($amount === 0.0 || $row->account === null) {
                    continue;
                }

                // `amount` is always stated in the account's own normal
                // direction, exactly like the NS sheet of the workbook.
                $onCreditSide = $row->account->saldo_normal === 'K';
                $debit = $onCreditSide ? 0.0 : $amount;
                $credit = $onCreditSide ? $amount : 0.0;
                $balance = round($balance + $debit - $credit, 2);

                $push($date, 'SALDO-AWAL-'.$date, 'Saldo awal '.$branch, (string) $row->account->code, $branch, JournalEntry::EVENT_OPENING, $debit, $credit, $meta);
            }

            // An opening trial balance that does not balance is plugged to
            // retained earnings instead of being dropped: the imbalance stays
            // visible on 3-112 and the posting service still gets balanced
            // entries.
            if (abs($balance) >= 0.005) {
                $push(
                    $date,
                    'SALDO-AWAL-'.$date,
                    'Selisih saldo awal dibukukan ke laba ditahan',
                    self::ACCOUNT_RETAINED_EARNINGS,
                    $branch,
                    JournalEntry::EVENT_OPENING,
                    $balance < 0 ? -$balance : 0.0,
                    $balance > 0 ? $balance : 0.0,
                    $meta
                );
            }
        }

        usort($rows, static fn (array $a, array $b): int => [$a['date'], $a['ref'], $a['source_id'], $a['source']] <=> [$b['date'], $b['ref'], $b['source_id'], $b['source']]);

        return $rows;
    }

    /**
     * @param  array<int, array{debit: float, credit: float}>  $rows
     * @return array{debit: float, credit: float, balanced: bool, difference: float}
     */
    public static function totals(array $rows): array
    {
        $debit = round(array_sum(array_column($rows, 'debit')), 2);
        $credit = round(array_sum(array_column($rows, 'credit')), 2);
        $difference = round($debit - $credit, 2);

        return [
            'debit' => $debit,
            'credit' => $credit,
            'balanced' => abs($difference) < 0.005,
            'difference' => $difference,
        ];
    }
}
