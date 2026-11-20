<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DeliveryOrder;
use App\Models\Payable;
use App\Models\Transportir;
use App\Models\User;
use App\Services\PaymentLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BALI-17: Transportir payables keep one row per payment instalment, linked to
 * the Loading Order (LO) it settles, and paid_amount is always the ledger sum.
 */
class PayablePaymentTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $pusat;
    private User $ownerCabang;
    private User $staffGudang;
    private Transportir $transportir;
    private DeliveryOrder $lot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'code'   => 'PST',
            'name'   => 'SUM Pusat',
            'city'   => 'Jakarta',
            'status' => 'active',
        ]);

        $this->pusat = User::create([
            'name'      => 'Pusat User',
            'email'     => 'pusat@test.com',
            'password'  => bcrypt('password'),
            'role'      => 'owner_pusat',
            'branch_id' => $this->branch->id,
            'status'    => 'active',
        ]);

        $this->ownerCabang = User::create([
            'name'      => 'Cabang User',
            'email'     => 'cabang@test.com',
            'password'  => bcrypt('password'),
            'role'      => 'owner_cabang',
            'branch_id' => $this->branch->id,
            'status'    => 'active',
        ]);

        $this->staffGudang = User::create([
            'name'      => 'Gudang User',
            'email'     => 'gudang@test.com',
            'password'  => bcrypt('password'),
            'role'      => 'staff_gudang',
            'branch_id' => $this->branch->id,
            'status'    => 'active',
        ]);

        $this->transportir = Transportir::create([
            'name'   => 'PT Raja Sumber Cahaya',
            'code'   => 'TRP-01',
            'status' => 'active',
        ]);

        $this->lot = DeliveryOrder::create([
            'do_number'             => 'LOT2026-001',
            'document_type'         => 'lo',
            'lo_kind'               => 'transportir',
            'origin_branch_id'      => $this->branch->id,
            'destination_branch_id' => $this->branch->id,
            'transportir_id'        => $this->transportir->id,
            'cylinder_type'         => '12kg',
            'quantity_ordered'      => 100,
            'order_date'            => today()->toDateString(),
            'requested_by'          => $this->pusat->id,
            'status'                => 'draft',
        ]);
    }

    private function payable(array $overrides = []): Payable
    {
        return Payable::create(array_merge([
            'transportir_id'    => $this->transportir->id,
            'delivery_order_id' => $this->lot->id,
            'invoice_number'    => 'TRP-INV-2026-001',
            'description'       => 'Ongkos angkut 12kg',
            'amount'            => 1000000,
            'paid_amount'       => 0,
            'status'            => 'pending',
        ], $overrides));
    }

    #[Test]
    public function a_partial_payment_keeps_the_payable_pending_but_flags_it_partial(): void
    {
        $payable = $this->payable();

        app(PaymentLedger::class)->recordPayable($payable, [
            'amount'       => 250000,
            'paid_on'      => '2026-03-01',
            'method'       => 'giro',
            'reference_no' => 'GIRO-77',
        ], $this->pusat->id);

        $payable->refresh();

        $this->assertSame('pending', $payable->status);
        $this->assertSame('partial', $payable->payment_state);
        $this->assertSame(250000.0, (float) $payable->paid_amount);
        $this->assertSame(750000.0, $payable->balance);
        $this->assertNull($payable->paid_at);
        $this->assertSame(1, $payable->payments()->count());
    }

    #[Test]
    public function a_full_payment_marks_the_payable_paid_and_stamps_paid_at(): void
    {
        $payable = $this->payable();
        $ledger  = app(PaymentLedger::class);

        $ledger->recordPayable($payable, ['amount' => 600000], $this->pusat->id);
        $ledger->recordPayable($payable, ['amount' => 400000], $this->pusat->id);

        $payable->refresh();

        $this->assertSame('paid', $payable->status);
        $this->assertSame('paid', $payable->payment_state);
        $this->assertSame(0.0, $payable->balance);
        $this->assertNotNull($payable->paid_at);
        $this->assertSame(2, $payable->payments()->count());
        $this->assertSame(1000000.0, (float) $payable->payments()->sum('amount'));
    }

    #[Test]
    public function the_legacy_pay_endpoint_records_only_the_difference_between_targets(): void
    {
        $payable = $this->payable();

        $this->actingAs($this->pusat, 'sanctum');

        $this->postJson("/api/v1/payables/{$payable->id}/pay", ['paid_amount' => 400000])
            ->assertOk()
            ->assertJsonPath('data.paid_amount', 400000);

        // A second call still sends the new absolute total, exactly like the
        // mobile dialog that is prefilled with the balance.
        $this->postJson("/api/v1/payables/{$payable->id}/pay", ['paid_amount' => 1000000])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $payable->refresh();

        $this->assertSame(2, $payable->payments()->count());
        $this->assertSame(1000000.0, (float) $payable->paid_amount);
        $this->assertSame(600000.0, (float) $payable->payments()->orderByDesc('id')->first()->amount);
    }

    #[Test]
    public function the_ledger_endpoint_stores_a_detailed_instalment_with_its_lo(): void
    {
        $payable = $this->payable();

        $this->actingAs($this->pusat, 'sanctum');

        $response = $this->postJson("/api/v1/payables/{$payable->id}/payments", [
            'amount'       => 300000,
            'paid_on'      => '2026-04-01',
            'method'       => 'transfer',
            'reference_no' => 'TRF-2026-04',
            'notes'        => 'Termin 1',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.amount', 300000)
            ->assertJsonPath('data.method', 'transfer')
            ->assertJsonPath('data.lo_number', 'LOT2026-001')
            ->assertJsonPath('data.created_by', 'Pusat User');

        $this->assertSame(300000.0, (float) $payable->fresh()->paid_amount);
        $this->assertSame('2026-04-01', $response->json('data.paid_on'));
    }

    #[Test]
    public function a_payment_above_the_remaining_balance_is_rejected(): void
    {
        $payable = $this->payable();

        $this->actingAs($this->pusat, 'sanctum');

        $this->postJson("/api/v1/payables/{$payable->id}/payments", ['amount' => 1000001])
            ->assertStatus(422);

        $this->assertSame(0, $payable->payments()->count());
    }

    #[Test]
    public function the_history_endpoint_lists_payments_newest_first(): void
    {
        $payable = $this->payable();
        $ledger  = app(PaymentLedger::class);

        $ledger->recordPayable($payable, ['amount' => 100000, 'paid_on' => '2026-01-15', 'method' => 'cash'], $this->pusat->id);
        $ledger->recordPayable($payable, ['amount' => 200000, 'paid_on' => '2026-02-15', 'method' => 'giro'], $this->pusat->id);

        $this->actingAs($this->pusat, 'sanctum');

        $response = $this->getJson("/api/v1/payables/{$payable->id}/payments")->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame('2026-02-15', $response->json('data.0.paid_on'));
        $this->assertSame(200000.0, (float) $response->json('data.0.amount'));
        $this->assertSame('2026-01-15', $response->json('data.1.paid_on'));
    }

    #[Test]
    public function only_owner_pusat_can_reverse_a_payment(): void
    {
        $payable = $this->payable();

        $payment = app(PaymentLedger::class)->recordPayable($payable, ['amount' => 400000], $this->pusat->id);

        $this->actingAs($this->ownerCabang, 'sanctum');
        $this->deleteJson("/api/v1/payables/{$payable->id}/payments/{$payment->id}")->assertForbidden();

        $this->assertSame(1, $payable->payments()->count());

        $this->actingAs($this->pusat, 'sanctum');
        $this->deleteJson("/api/v1/payables/{$payable->id}/payments/{$payment->id}")->assertOk();

        $payable->refresh();

        $this->assertSame(0, $payable->payments()->count());
        $this->assertSame(0.0, (float) $payable->paid_amount);
        $this->assertSame('pending', $payable->status);
        $this->assertNull($payable->paid_at);
    }

    #[Test]
    public function staff_gudang_cannot_record_a_payable_payment(): void
    {
        $payable = $this->payable();

        $this->actingAs($this->staffGudang, 'sanctum');

        $this->postJson("/api/v1/payables/{$payable->id}/payments", ['amount' => 50000])
            ->assertForbidden();

        $this->getJson("/api/v1/payables/{$payable->id}/payments")->assertForbidden();

        $this->assertSame(0, $payable->payments()->count());
    }

    #[Test]
    public function a_payment_cannot_be_reversed_through_another_payable(): void
    {
        $payable = $this->payable();
        $other   = $this->payable(['invoice_number' => 'TRP-INV-2026-002']);

        $payment = app(PaymentLedger::class)->recordPayable($payable, ['amount' => 100000], $this->pusat->id);

        $this->actingAs($this->pusat, 'sanctum');

        $this->deleteJson("/api/v1/payables/{$other->id}/payments/{$payment->id}")->assertNotFound();

        $this->assertSame(1, $payable->payments()->count());
    }
}
