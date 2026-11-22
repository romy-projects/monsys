<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 19B: one transfer, many documents (Option A).
 *
 * Until now every document type kept its own payment table
 * (`invoice_payments`, `receivable_payments`, `payable_payments`), so a single
 * bank transfer that settles two invoices had to be entered twice — with two
 * journal entries and two cash lines for money that left/entered the bank once.
 *
 * The new model is a payment *header* (one transfer: date, method, Kas/Bank,
 * branch, reference) plus `payment_details` rows that allocate the header amount
 * to invoices / receivables / payables. A header always carries
 * `amount = SUM(details.amount)`, which is what the journal posts against.
 *
 * Backfill: every legacy payment row becomes one header with exactly one detail,
 * and `journal_entries.source_type` / `source_id` are rewritten to the new
 * `payment` header *before* the old tables are dropped, so
 * `JournalPostingService::sync()` sees the payment as still alive instead of
 * voiding and reposting history.
 */
return new class extends Migration
{
    /** Prefix of `payments.payment_no`, mirroring DocumentNumberService's PREFIXES. */
    private const PAYMENT_PREFIX = 'PM';

    /** Legacy table => [journal source_type, direction, target column] */
    private const LEGACY = [
        'invoice_payments' => ['invoice_payment', 'in', 'invoice_id'],
        'receivable_payments' => ['receivable_payment', 'in', 'receivable_id'],
        'payable_payments' => ['payable_payment', 'out', 'payable_id'],
    ];

    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_no', 30)->unique();
            $table->enum('direction', ['in', 'out'])->default('in')
                ->comment('in = uang masuk (invoice/receivable), out = uang keluar (payable)');
            $table->date('paid_on');
            $table->enum('method', ['cash', 'transfer', 'giro', 'other'])->default('cash');
            $table->foreignId('cash_account_id')->nullable()
                ->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 15, 2)->default(0)
                ->comment('Selalu SUM(payment_details.amount); satu transfer = satu header');
            $table->string('reference_no', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['direction', 'paid_on']);
            $table->index(['branch_id', 'paid_on']);
        });

        Schema::create('payment_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('payable_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('receivable_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_order_id')->nullable()
                ->constrained('delivery_orders')->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->index('invoice_id');
            $table->index('payable_id');
            $table->index('receivable_id');
        });

        $this->backfillLegacyPayments();

        Schema::dropIfExists('invoice_payments');
        Schema::dropIfExists('receivable_payments');
        Schema::dropIfExists('payable_payments');
    }

    /**
     * Copy every legacy payment row into one header + one detail, then point the
     * existing journal entries at the header. Runs inside a transaction so a
     * half-migrated ledger never reaches the old-table drop.
     */
    private function backfillLegacyPayments(): void
    {
        DB::transaction(function (): void {
            $sequences = [];

            foreach (self::LEGACY as $table => [$sourceType, $direction, $targetColumn]) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                foreach (DB::table($table)->orderBy('id')->get() as $row) {
                    $paymentId = $this->insertHeader($row, $direction, $targetColumn, $sequences);

                    DB::table('payment_details')->insert([
                        'payment_id' => $paymentId,
                        'invoice_id' => $targetColumn === 'invoice_id' ? $row->invoice_id : null,
                        'payable_id' => $targetColumn === 'payable_id' ? $row->payable_id : null,
                        'receivable_id' => $targetColumn === 'receivable_id' ? $row->receivable_id : null,
                        'delivery_order_id' => $row->delivery_order_id ?? null,
                        'amount' => $row->amount,
                        'notes' => $row->notes ?? null,
                        'created_at' => $row->created_at ?? now(),
                        'updated_at' => $row->updated_at ?? now(),
                    ]);

                    if (Schema::hasTable('journal_entries')) {
                        DB::table('journal_entries')
                            ->where('source_type', $sourceType)
                            ->where('source_id', $row->id)
                            ->update([
                                'source_type' => 'payment',
                                'source_id' => $paymentId,
                                'updated_at' => now(),
                            ]);
                    }
                }
            }

            $this->storeSequences($sequences);
        });
    }

    /**
     * @param  array<int, int>  $sequences  year => last allocated number
     */
    private function insertHeader(object $row, string $direction, string $targetColumn, array &$sequences): int
    {
        $paidOn = $row->paid_on ?: now()->toDateString();
        $year = (int) substr((string) $paidOn, 0, 4);

        $sequences[$year] = ($sequences[$year] ?? 0) + 1;

        return DB::table('payments')->insertGetId([
            'payment_no' => self::PAYMENT_PREFIX.$year.'-'.str_pad((string) $sequences[$year], 3, '0', STR_PAD_LEFT),
            'direction' => $direction,
            'paid_on' => $paidOn,
            'method' => $row->method ?: 'cash',
            'cash_account_id' => $row->cash_account_id ?? null,
            'branch_id' => $this->branchIdFor($targetColumn, $row),
            'amount' => $row->amount,
            'reference_no' => $row->reference_no ?? null,
            'notes' => $row->notes ?? null,
            'created_by' => $row->created_by ?? null,
            'created_at' => $row->created_at ?? now(),
            'updated_at' => $row->updated_at ?? now(),
        ]);
    }

    private function branchIdFor(string $targetColumn, object $row): ?int
    {
        $documentTable = match ($targetColumn) {
            'invoice_id' => 'invoices',
            'receivable_id' => 'receivables',
            default => 'payables',
        };

        $documentId = $row->{$targetColumn} ?? null;

        if (! $documentId || ! Schema::hasTable($documentTable)) {
            return null;
        }

        if ($targetColumn !== 'payable_id') {
            return DB::table($documentTable)->where('id', $documentId)->value('branch_id');
        }

        // A Transportir payable belongs to the LO's origin branch (the branch
        // that requested the transport); fall back to the destination branch.
        $deliveryOrderId = $row->delivery_order_id
            ?? DB::table('payables')->where('id', $documentId)->value('delivery_order_id');

        if (! $deliveryOrderId) {
            return null;
        }

        $deliveryOrder = DB::table('delivery_orders')
            ->where('id', $deliveryOrderId)
            ->first(['origin_branch_id', 'destination_branch_id']);

        return $deliveryOrder?->origin_branch_id ?? $deliveryOrder?->destination_branch_id;
    }

    /**
     * Keep DocumentNumberService's 'pmt' counter in step with the backfilled
     * PM numbers, so the next recorded payment continues the series.
     *
     * @param  array<int, int>  $sequences
     */
    private function storeSequences(array $sequences): void
    {
        if (! Schema::hasTable('document_sequences')) {
            return;
        }

        foreach ($sequences as $year => $last) {
            $existing = DB::table('document_sequences')
                ->where('document_type', 'pmt')
                ->where('year', $year)
                ->first();

            if ($existing) {
                DB::table('document_sequences')->where('id', $existing->id)->update([
                    'last_number' => max((int) $existing->last_number, $last),
                    'updated_at' => now(),
                ]);

                continue;
            }

            DB::table('document_sequences')->insert([
                'document_type' => 'pmt',
                'year' => $year,
                'last_number' => $last,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Best-effort reverse: rebuild the three legacy tables from the details.
     *
     * Allocations that share a header are written back as separate legacy rows
     * (the header's date/method/cash account are copied to each), which is the
     * most the old schema can express.
     */
    public function down(): void
    {
        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->date('paid_on');
            $table->decimal('amount', 14, 2);
            $table->string('method', 20)->default('cash');
            $table->foreignId('cash_account_id')->nullable()
                ->constrained('chart_of_accounts')->nullOnDelete();
            $table->string('reference_no', 50)->nullable();
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['invoice_id', 'paid_on']);
        });

        Schema::create('receivable_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receivable_id')->constrained()->cascadeOnDelete();
            $table->date('paid_on');
            $table->decimal('amount', 15, 2);
            $table->enum('method', ['cash', 'transfer', 'giro', 'other'])->default('cash');
            $table->string('reference_no', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['receivable_id', 'paid_on']);
        });

        Schema::create('payable_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payable_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_order_id')->nullable()->constrained('delivery_orders')->nullOnDelete();
            $table->date('paid_on');
            $table->decimal('amount', 15, 2);
            $table->enum('method', ['cash', 'transfer', 'giro', 'other'])->default('cash');
            $table->string('reference_no', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['payable_id', 'paid_on']);
        });

        Schema::table('payable_payments', function (Blueprint $table) {
            $table->foreignId('cash_account_id')->nullable()->after('method')
                ->constrained('chart_of_accounts')->nullOnDelete();
        });

        DB::transaction(function (): void {
            $rows = DB::table('payment_details as d')
                ->join('payments as p', 'p.id', '=', 'd.payment_id')
                ->select('d.*', 'p.paid_on', 'p.method', 'p.cash_account_id', 'p.reference_no', 'p.created_by')
                ->orderBy('d.id')
                ->get();

            foreach ($rows as $row) {
                [$table, $sourceType, $targetColumn] = match (true) {
                    $row->invoice_id !== null => ['invoice_payments', 'invoice_payment', 'invoice_id'],
                    $row->receivable_id !== null => ['receivable_payments', 'receivable_payment', 'receivable_id'],
                    default => ['payable_payments', 'payable_payment', 'payable_id'],
                };

                $payload = [
                    'paid_on' => $row->paid_on,
                    'amount' => $row->amount,
                    'method' => $row->method,
                    'reference_no' => $row->reference_no,
                    'notes' => $row->notes,
                    'created_by' => $row->created_by,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                    $targetColumn => $row->{$targetColumn},
                ];

                if ($table === 'invoice_payments') {
                    $payload['cash_account_id'] = $row->cash_account_id;
                    // The legacy column is 50 chars wide.
                    $payload['reference_no'] = $row->reference_no === null
                        ? null
                        : mb_substr((string) $row->reference_no, 0, 50);
                }

                if ($table === 'payable_payments') {
                    $payload['cash_account_id'] = $row->cash_account_id;
                    $payload['delivery_order_id'] = $row->delivery_order_id;
                }

                $legacyId = DB::table($table)->insertGetId($payload);

                if ($table === 'invoice_payments' && Schema::hasTable('journal_entries')) {
                    DB::table('journal_entries')
                        ->where('source_type', 'payment')
                        ->where('source_id', $row->payment_id)
                        ->update([
                            'source_type' => $sourceType,
                            'source_id' => $legacyId,
                            'updated_at' => now(),
                        ]);
                }
            }
        });

        Schema::dropIfExists('payment_details');
        Schema::dropIfExists('payments');
    }
};
