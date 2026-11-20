<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CylinderReconciliation;
use App\Models\DeliveryOrder;
use App\Models\LpgPrice;
use App\Models\ReconciliationSettlement;
use App\Models\User;
use App\Services\CylinderReconciliationService;
use App\Filament\Resources\ReconciliationResource\Pages\ListReconciliations;
use App\Filament\Resources\ReconciliationResource\Pages\ViewReconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BALI-18…22: cylinder reconciliation across PO → SO → LO.
 *
 * D4: quantity_ordered is the authoritative reconcile quantity on every leg.
 * D5: settlements store quantity + value at the PO unit-price snapshot.
 */
class CylinderReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $pusat;
    private User $branchOwner;
    private User $otherOwner;
    private Branch $mainBranch;
    private Branch $branch;
    private Branch $otherBranch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mainBranch = Branch::create([
            'code' => 'PST', 'name' => 'SUM Pusat', 'city' => 'Jakarta', 'status' => 'active',
        ]);
        $this->branch = Branch::create([
            'code' => 'BDG', 'name' => 'SUM Bandung', 'city' => 'Bandung', 'status' => 'active',
        ]);
        $this->otherBranch = Branch::create([
            'code' => 'SRG', 'name' => 'SUM Surabaya', 'city' => 'Surabaya', 'status' => 'active',
        ]);

        $this->pusat = User::create([
            'name' => 'Pusat', 'email' => 'pusat@recon.test', 'password' => bcrypt('password'),
            'role' => 'owner_pusat', 'branch_id' => $this->mainBranch->id, 'status' => 'active',
        ]);
        $this->branchOwner = User::create([
            'name' => 'Bandung Owner', 'email' => 'bandung@recon.test', 'password' => bcrypt('password'),
            'role' => 'owner_cabang', 'branch_id' => $this->branch->id, 'status' => 'active',
        ]);
        $this->otherOwner = User::create([
            'name' => 'Surabaya Owner', 'email' => 'surabaya@recon.test', 'password' => bcrypt('password'),
            'role' => 'owner_cabang', 'branch_id' => $this->otherBranch->id, 'status' => 'active',
        ]);
    }

    /**
     * Build and deliver the PO → SO → LO chain, then return the three rows.
     *
     * @return array{0: DeliveryOrder, 1: DeliveryOrder, 2: DeliveryOrder}
     */
    private function deliverChain(int $paid, int $redeemed, int $delivered, string $suffix = '001'): array
    {
        $po = DeliveryOrder::create([
            'do_number'             => "PO2026-{$suffix}",
            'document_type'         => 'po',
            'counterparty_type'     => 'branch',
            'counterparty_name'     => 'SUM Pusat',
            'origin_branch_id'      => $this->mainBranch->id,
            'destination_branch_id' => $this->branch->id,
            'cylinder_type'         => '3kg',
            'quantity_ordered'      => $paid,
            'order_date'            => '2026-08-10',
            'requested_by'          => $this->branchOwner->id,
            'status'                => 'approved',
        ]);

        $so = DeliveryOrder::create([
            'do_number'             => "SO2026-{$suffix}",
            'document_type'         => 'so',
            'counterparty_type'     => 'pertamina',
            'counterparty_name'     => 'Pertamina',
            'po_number'             => $po->do_number,
            'origin_branch_id'      => $this->mainBranch->id,
            'destination_branch_id' => $this->branch->id,
            'cylinder_type'         => '3kg',
            'quantity_ordered'      => $redeemed,
            'order_date'            => '2026-08-10',
            'requested_by'          => $this->pusat->id,
            'status'                => 'approved',
        ]);

        // D5: the PO carries the purchase-price snapshot used for every settlement value.
        LpgPrice::create([
            'branch_id'         => $this->branch->id,
            'purchase_order_id' => $po->id,
            'cylinder_type'     => '3kg',
            'purchase_price'    => 10000,
            'selling_price'     => 13000,
            'effective_date'    => '2026-08-01',
            'created_by'        => $this->pusat->id,
        ]);

        $lo = DeliveryOrder::create([
            'do_number'             => "LOT2026-{$suffix}",
            'document_type'         => 'lo',
            'lo_kind'               => 'transportir',
            'po_number'             => $po->do_number,
            'so_number'             => $so->do_number,
            'origin_branch_id'      => $this->mainBranch->id,
            'destination_branch_id' => $this->branch->id,
            'cylinder_type'         => '3kg',
            'quantity_ordered'      => $delivered,
            'order_date'            => '2026-08-11',
            'requested_by'          => $this->pusat->id,
            'status'                => 'approved',
        ]);

        // Delivery is what triggers reconciliation.
        $lo->update(['status' => 'delivered']);

        return [$po, $so, $lo->fresh()];
    }

    #[Test]
    public function case_1_records_an_hq_credit_for_unredeemed_paid_units(): void
    {
        [$po] = $this->deliverChain(100, 90, 90);

        $recon = CylinderReconciliation::where('purchase_order_id', $po->id)->sole();

        $this->assertSame('case_1', $recon->case_type);
        $this->assertSame(100, $recon->quantity_paid);
        $this->assertSame(90, $recon->quantity_redeemed);
        $this->assertSame(90, $recon->quantity_delivered);
        $this->assertSame(10, $recon->quantity_unredeemed);
        $this->assertSame(0, $recon->quantity_short_delivered);
        $this->assertSame(0, $recon->quantity_over_delivered);
        $this->assertSame('10000.00', (string) $recon->unit_price);

        $this->assertSame(1, $recon->settlements()->count());

        $credit = $recon->settlements()->where('settlement_type', 'unredeemed_credit')->sole();
        $this->assertSame('hq', $credit->party);
        $this->assertSame(10, $credit->quantity);
        $this->assertSame('100000.00', (string) $credit->amount);
        $this->assertSame('open', $credit->status);
    }

    #[Test]
    public function case_2_records_a_branch_credit_and_a_separate_transportir_claim(): void
    {
        [$po] = $this->deliverChain(100, 100, 95, '002');

        $recon = CylinderReconciliation::where('purchase_order_id', $po->id)->sole();

        $this->assertSame('case_2', $recon->case_type);
        $this->assertSame(5, $recon->quantity_short_delivered);
        $this->assertSame(0, $recon->quantity_unredeemed);
        $this->assertSame(0, $recon->quantity_over_delivered);

        $settlements = $recon->settlements()->get()->keyBy('settlement_type');

        $this->assertCount(2, $settlements);
        $this->assertSame('hq', $settlements['short_delivery_credit']->party);
        $this->assertSame(5, $settlements['short_delivery_credit']->quantity);
        $this->assertSame('50000.00', (string) $settlements['short_delivery_credit']->amount);

        $this->assertSame('transportir', $settlements['transportir_short_claim']->party);
        $this->assertSame(5, $settlements['transportir_short_claim']->quantity);
        $this->assertSame('50000.00', (string) $settlements['transportir_short_claim']->amount);
    }

    #[Test]
    public function case_3_carries_the_extra_units_to_the_next_delivery(): void
    {
        [$po] = $this->deliverChain(100, 100, 105, '003');

        $recon = CylinderReconciliation::where('purchase_order_id', $po->id)->sole();

        $this->assertSame('case_3', $recon->case_type);
        $this->assertSame(5, $recon->quantity_over_delivered);
        $this->assertSame(0, $recon->quantity_short_delivered);

        $claim = $recon->settlements()->where('settlement_type', 'over_delivery_claim')->sole();

        $this->assertSame('branch', $claim->party);
        $this->assertSame(5, $claim->quantity);
        $this->assertSame('50000.00', (string) $claim->amount);
        $this->assertTrue($claim->applies_to_next_delivery);
        $this->assertSame($po->id, $claim->source_purchase_order_id);
    }

    #[Test]
    public function a_balanced_chain_produces_no_settlements(): void
    {
        [$po] = $this->deliverChain(100, 100, 100, '004');

        $recon = CylinderReconciliation::where('purchase_order_id', $po->id)->sole();

        $this->assertSame('balanced', $recon->case_type);
        $this->assertSame(0, $recon->settlements()->count());
    }

    #[Test]
    public function reprocessing_never_duplicates_the_reconciliation_or_its_settlements(): void
    {
        [, , $lo] = $this->deliverChain(100, 100, 95, '005');

        $service = app(CylinderReconciliationService::class);
        $service->reconcile($lo, $this->pusat->id);
        $service->reconcile($lo->fresh(), $this->pusat->id);

        $this->assertSame(1, CylinderReconciliation::count());
        $this->assertSame(2, ReconciliationSettlement::count());
    }

    #[Test]
    public function an_lo_without_a_po_is_not_reconciled(): void
    {
        $lo = DeliveryOrder::create([
            'do_number'             => 'LOT2026-404',
            'document_type'         => 'lo',
            'lo_kind'               => 'transportir',
            'origin_branch_id'      => $this->mainBranch->id,
            'destination_branch_id' => $this->branch->id,
            'cylinder_type'         => '3kg',
            'quantity_ordered'      => 50,
            'order_date'            => '2026-08-11',
            'requested_by'          => $this->pusat->id,
            'status'                => 'approved',
        ]);

        $lo->update(['status' => 'delivered']);

        $this->assertSame(0, CylinderReconciliation::count());
        $this->assertNull(app(CylinderReconciliationService::class)->reconcile($lo->fresh()));
    }

    #[Test]
    public function an_undelivered_lo_is_not_reconciled(): void
    {
        $lo = DeliveryOrder::create([
            'do_number'             => 'LOT2026-405',
            'document_type'         => 'lo',
            'lo_kind'               => 'transportir',
            'po_number'             => 'PO2026-405',
            'origin_branch_id'      => $this->mainBranch->id,
            'destination_branch_id' => $this->branch->id,
            'cylinder_type'         => '3kg',
            'quantity_ordered'      => 50,
            'order_date'            => '2026-08-11',
            'requested_by'          => $this->pusat->id,
            'status'                => 'approved',
        ]);

        $this->assertNull(app(CylinderReconciliationService::class)->reconcile($lo));
        $this->assertSame(0, CylinderReconciliation::count());
    }

    #[Test]
    public function reconciliation_index_is_branch_scoped_while_pusat_sees_everything(): void
    {
        $this->deliverChain(100, 90, 90, '010');

        $this->actingAs($this->pusat, 'sanctum');
        $this->getJson('/api/v1/reconciliations')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.case_type', 'case_1')
            ->assertJsonPath('data.0.branch.id', $this->branch->id);

        $this->actingAs($this->branchOwner, 'sanctum');
        $this->getJson('/api/v1/reconciliations')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1);

        $this->actingAs($this->otherOwner, 'sanctum');
        $this->getJson('/api/v1/reconciliations')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 0);
    }

    #[Test]
    public function a_branch_user_cannot_force_a_reprocessing(): void
    {
        [, , $lo] = $this->deliverChain(100, 100, 95, '011');

        $this->actingAs($this->branchOwner, 'sanctum');

        $this->postJson('/api/v1/reconciliations', ['loading_order_id' => $lo->id])
            ->assertStatus(403);
    }

    #[Test]
    public function pusat_can_force_a_reprocessing_through_the_api_idempotently(): void
    {
        [, , $lo] = $this->deliverChain(100, 100, 95, '012');

        $this->actingAs($this->pusat, 'sanctum');

        $this->postJson('/api/v1/reconciliations', ['loading_order_id' => $lo->id])
            ->assertStatus(201)
            ->assertJsonPath('data.case_type', 'case_2');

        $this->postJson('/api/v1/reconciliations', ['loading_order_id' => $lo->id])
            ->assertStatus(201);

        $this->assertSame(1, CylinderReconciliation::count());
        $this->assertSame(2, ReconciliationSettlement::count());
    }

    #[Test]
    public function reconciliation_pages_render(): void
    {
        [$po] = $this->deliverChain(100, 90, 90, '013');

        $recon = CylinderReconciliation::where('purchase_order_id', $po->id)->sole();

        $this->actingAs($this->pusat);

        Livewire::test(ListReconciliations::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$recon]);

        Livewire::test(ViewReconciliation::class, ['record' => $recon->getRouteKey()])
            ->assertOk()
            ->assertSee('Unredeemed');
    }
}
