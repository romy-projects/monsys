<?php

namespace Tests\Feature;

use App\Filament\Resources\PayableResource\Pages\CreatePayable;
use App\Filament\Resources\PayableResource\Pages\EditPayable;
use App\Filament\Resources\PayableResource\Pages\ListPayables;
use App\Filament\Resources\ReceivableResource\Pages\CreateReceivable;
use App\Filament\Resources\ReceivableResource\Pages\EditReceivable;
use App\Filament\Resources\ReceivableResource\Pages\ListReceivables;
use App\Models\Branch;
use App\Models\DeliveryOrder;
use App\Models\Payable;
use App\Models\Receivable;
use App\Models\Transportir;
use App\Models\User;
use App\Services\PaymentLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BALI-16 / BALI-17: the Filament payment actions must build their forms and go
 * through the payment ledger (the action form closes over the record, which only
 * fails when the modal is actually rendered).
 */
class PaymentResourceSmokeTest extends TestCase
{
    use RefreshDatabase;

    private User $pusat;

    private Receivable $receivable;

    private Payable $payable;

    protected function setUp(): void
    {
        parent::setUp();

        $branch = Branch::create([
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
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        $this->actingAs($this->pusat);

        $this->receivable = Receivable::create([
            'branch_id' => $branch->id,
            'buyer_name' => 'Toko Sinar Jaya',
            'buyer_type' => 'retail',
            'invoice_number' => 'INV-SMOKE-1',
            'invoice_date' => today()->toDateString(),
            'due_date' => today()->addDays(30)->toDateString(),
            'amount' => 1000000,
            'paid_amount' => 0,
            'status' => 'outstanding',
            'created_by' => $this->pusat->id,
        ]);

        $transportir = Transportir::create([
            'name' => 'PT Raja Sumber Cahaya',
            'code' => 'TRP-01',
            'status' => 'active',
        ]);

        $lot = DeliveryOrder::create([
            'do_number' => 'LOT2026-900',
            'document_type' => 'lo',
            'lo_kind' => 'transportir',
            'origin_branch_id' => $branch->id,
            'destination_branch_id' => $branch->id,
            'transportir_id' => $transportir->id,
            'cylinder_type' => '12kg',
            'quantity_ordered' => 100,
            'order_date' => today()->toDateString(),
            'requested_by' => $this->pusat->id,
            'status' => 'draft',
        ]);

        $this->payable = Payable::create([
            'transportir_id' => $transportir->id,
            'delivery_order_id' => $lot->id,
            'invoice_number' => 'TRP-INV-SMOKE-1',
            'description' => 'Ongkos angkut 12kg',
            'amount' => 1000000,
            'paid_amount' => 0,
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function receivable_payment_actions_render_and_record_through_the_ledger(): void
    {
        Livewire::test(ListReceivables::class)
            ->assertOk()
            ->assertTableActionExists('record_payment')
            ->assertTableActionExists('payments')
            ->callTableAction('record_payment', $this->receivable, [
                'paid_on' => today()->toDateString(),
                'amount' => 250000,
                'method' => 'transfer',
                'reference_no' => 'TRF-SMOKE-1',
                'notes' => 'Termin 1',
            ])
            ->assertHasNoTableActionErrors();

        $this->receivable->refresh();

        $this->assertSame(250000.0, (float) $this->receivable->paid_amount);
        $this->assertSame('partial', $this->receivable->status);
        $this->assertSame(1, $this->receivable->payments()->count());

        Livewire::test(EditReceivable::class, ['record' => $this->receivable->id])
            ->assertOk()
            ->assertActionExists('payments');
    }

    #[Test]
    public function payable_payment_actions_render_and_record_through_the_ledger(): void
    {
        Livewire::test(ListPayables::class)
            ->assertOk()
            ->assertTableActionExists('record_payment')
            ->assertTableActionExists('mark_as_paid')
            ->callTableAction('record_payment', $this->payable, [
                'paid_on' => today()->toDateString(),
                'amount' => 400000,
                'method' => 'cash',
            ])
            ->assertHasNoTableActionErrors();

        $this->payable->refresh();

        $this->assertSame(400000.0, (float) $this->payable->paid_amount);
        $this->assertSame('partial', $this->payable->payment_state);

        Livewire::test(ListPayables::class)
            ->callTableAction('mark_as_paid', $this->payable)
            ->assertHasNoTableActionErrors();

        $this->payable->refresh();

        $this->assertSame('paid', $this->payable->status);
        $this->assertSame(2, $this->payable->payments()->count());

        Livewire::test(EditPayable::class, ['record' => $this->payable->id])
            ->assertOk()
            ->assertActionExists('payments');
    }

    #[Test]
    public function the_create_forms_render_with_the_ledger_placeholders(): void
    {
        // The paid_amount / status placeholders resolve a null record on create.
        Livewire::test(CreateReceivable::class)
            ->assertOk()
            ->assertFormFieldExists('buyer_name')
            ->assertFormFieldExists('amount');

        Livewire::test(CreatePayable::class)
            ->assertOk()
            ->assertFormFieldExists('transportir_id')
            ->assertSee('Paid Amount (Rp)')
            ->assertSee('Status');
    }

    #[Test]
    public function a_payment_can_be_voided_from_the_payable_table(): void
    {
        $payment = app(PaymentLedger::class)
            ->recordPayable($this->payable, ['amount' => 300000], $this->pusat->id);

        Livewire::test(ListPayables::class)
            ->assertTableActionExists('void_payment')
            ->callTableAction('void_payment', $this->payable, ['payment_id' => $payment->id])
            ->assertHasNoTableActionErrors();

        $this->payable->refresh();

        $this->assertSame(0.0, (float) $this->payable->paid_amount);
        $this->assertSame('pending', $this->payable->status);
        $this->assertSame(0, $this->payable->payments()->count());
    }
}
