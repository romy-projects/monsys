<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Receivable;
use App\Models\User;
use App\Services\PaymentLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BALI-16: receivable payments are stored one row per instalment and the
 * receivable's paid_amount / status are always derived from that ledger.
 */
class ReceivablePaymentTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $pusat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'code' => 'PST',
            'name' => 'SUM Pusat',
            'city' => 'Jakarta',
            'status' => 'active',
        ]);

        $this->pusat = User::create([
            'name' => 'Pusat User',
            'email' => 'pusat@test.com',
            'password' => bcrypt('password'),
            'role' => 'owner_pusat',
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);
    }

    private function receivable(array $overrides = []): Receivable
    {
        return Receivable::create(array_merge([
            'branch_id' => $this->branch->id,
            'buyer_name' => 'Toko Sinar Jaya',
            'buyer_type' => 'retail',
            'invoice_number' => 'INV-2026-001',
            'invoice_date' => today()->toDateString(),
            'due_date' => today()->addDays(30)->toDateString(),
            'amount' => 1000000,
            'paid_amount' => 0,
            'status' => 'outstanding',
            'created_by' => $this->pusat->id,
        ], $overrides));
    }

    #[Test]
    public function a_partial_payment_marks_the_receivable_as_partial(): void
    {
        $receivable = $this->receivable();

        $payment = app(PaymentLedger::class)->recordReceivable($receivable, [
            'amount' => 400000,
            'paid_on' => today()->toDateString(),
            'method' => 'transfer',
            'reference_no' => 'TRF-991',
            'notes' => 'Transfer BCA',
        ], $this->pusat->id);

        $receivable->refresh();

        $this->assertSame('partial', $receivable->status);
        $this->assertSame(400000.0, (float) $receivable->paid_amount);
        $this->assertSame(600000.0, $receivable->balance);
        $this->assertSame('transfer', $payment->method);
        $this->assertSame('TRF-991', $payment->reference_no);
        $this->assertSame($this->pusat->id, $payment->created_by);
        $this->assertSame(1, $receivable->payments()->count());
    }

    #[Test]
    public function two_payments_accumulate_instead_of_replacing_each_other(): void
    {
        $receivable = $this->receivable();
        $ledger = app(PaymentLedger::class);

        $ledger->recordReceivable($receivable, ['amount' => 250000, 'paid_on' => '2026-01-10'], $this->pusat->id);
        $ledger->recordReceivable($receivable, ['amount' => 350000, 'paid_on' => '2026-02-10'], $this->pusat->id);

        $receivable->refresh();

        $this->assertSame(2, $receivable->payments()->count());
        $this->assertSame(600000.0, (float) $receivable->paid_amount);
        $this->assertSame(400000.0, $receivable->balance);
        $this->assertSame('partial', $receivable->status);
    }

    #[Test]
    public function paying_the_full_amount_marks_the_receivable_paid(): void
    {
        $receivable = $this->receivable();

        app(PaymentLedger::class)->recordReceivable($receivable, ['amount' => 600000], $this->pusat->id);
        app(PaymentLedger::class)->recordReceivable($receivable, ['amount' => 400000], $this->pusat->id);

        $receivable->refresh();

        $this->assertSame(0.0, $receivable->balance);
        $this->assertSame('paid', $receivable->status);
    }

    #[Test]
    public function a_payment_above_the_balance_is_rejected(): void
    {
        $receivable = $this->receivable();

        $this->expectException(ValidationException::class);

        app(PaymentLedger::class)->recordReceivable($receivable, ['amount' => 1000000.01], $this->pusat->id);
    }

    #[Test]
    public function zero_and_negative_payments_are_rejected(): void
    {
        $receivable = $this->receivable();

        foreach ([0, -50000] as $amount) {
            $rejected = false;

            try {
                app(PaymentLedger::class)->recordReceivable($receivable, ['amount' => $amount], $this->pusat->id);
            } catch (ValidationException) {
                $rejected = true;
            }

            $this->assertTrue($rejected, 'Amount '.$amount.' should have been rejected.');
        }

        $this->assertSame(0, $receivable->payments()->count());
        $this->assertSame(0.0, (float) $receivable->fresh()->paid_amount);
    }

    #[Test]
    public function history_keeps_every_instalment_ordered_by_payment_date(): void
    {
        $receivable = $this->receivable();
        $ledger = app(PaymentLedger::class);

        $ledger->recordReceivable($receivable, ['amount' => 100000, 'paid_on' => '2026-02-10', 'method' => 'giro'], $this->pusat->id);
        $ledger->recordReceivable($receivable, ['amount' => 150000, 'paid_on' => '2026-01-10', 'method' => 'cash'], $this->pusat->id);

        $history = $receivable->payments()->orderByDesc('paid_on')->orderByDesc('id')->get();

        $this->assertSame(250000.0, (float) $history->sum('amount'));
        $this->assertSame('2026-02-10', $history->first()->paid_on->toDateString());
        $this->assertSame('2026-01-10', $history->last()->paid_on->toDateString());
        $this->assertSame('giro', $history->first()->method);
    }

    #[Test]
    public function the_backfill_migration_turns_an_existing_paid_amount_into_one_opening_row(): void
    {
        $receivable = $this->receivable();

        // Simulate a legacy row: paid_amount set without any payment history.
        Receivable::whereKey($receivable->id)->update(['paid_amount' => 300000]);

        $migration = require database_path('migrations/2026_11_19_000007_backfill_payment_history_from_paid_amount.php');
        $migration->up();

        $rows = $receivable->payments()->get();

        $this->assertCount(1, $rows);
        $this->assertSame(300000.0, (float) $rows->first()->amount);
        $this->assertSame('other', $rows->first()->method);
        $this->assertSame('Saldo awal (migrasi dari paid_amount)', $rows->first()->notes);
        $this->assertSame($receivable->invoice_date->toDateString(), $rows->first()->paid_on->toDateString());
    }

    #[Test]
    public function reversing_a_payment_restores_the_previous_balance(): void
    {
        $receivable = $this->receivable();
        $ledger = app(PaymentLedger::class);

        $ledger->recordReceivable($receivable, ['amount' => 400000], $this->pusat->id);
        $payment = $ledger->recordReceivable($receivable, ['amount' => 600000], $this->pusat->id);

        $this->assertSame('paid', $receivable->fresh()->status);

        $ledger->reverseReceivablePayment($payment);

        $receivable->refresh();

        $this->assertSame(400000.0, (float) $receivable->paid_amount);
        $this->assertSame(600000.0, $receivable->balance);
        $this->assertSame('partial', $receivable->status);
        $this->assertSame(1, $receivable->payments()->count());
    }
}
