<?php

namespace Tests\Feature;

use App\Filament\Resources\LoadingOrderResource\Pages\CreateLoadingOrder;
use App\Filament\Resources\ReconciliationResource\Pages\ListReconciliations;
use App\Filament\Resources\ReconciliationResource\Pages\ViewReconciliation;
use App\Filament\Resources\ReconciliationResource\RelationManagers\ReconciliationSettlementsRelationManager;
use App\Models\Branch;
use App\Models\CylinderReconciliation;
use App\Models\DeliveryOrder;
use App\Models\LpgPrice;
use App\Models\ReconciliationSettlement;
use App\Models\User;
use App\Support\OutstandingReconciliations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * D6/D7 (2026-11-20): the outstanding credit/claim of a branch is displayed while
 * the admin enters the document that settles it, and the reference is written into
 * the notes. Nothing is deducted automatically.
 */
class OutstandingReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $pusat;
    private User $branchOwner;
    private Branch $mainBranch;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mainBranch = Branch::create([
            'code' => 'PST', 'name' => 'SUM Pusat', 'city' => 'Jakarta', 'status' => 'active',
        ]);
        $this->branch = Branch::create([
            'code' => 'BDG', 'name' => 'SUM Bandung', 'city' => 'Bandung', 'status' => 'active',
        ]);

        $this->pusat = User::create([
            'name' => 'Pusat', 'email' => 'pusat@outstanding.test', 'password' => bcrypt('password'),
            'role' => 'owner_pusat', 'branch_id' => $this->mainBranch->id, 'status' => 'active',
        ]);
        $this->branchOwner = User::create([
            'name' => 'Bandung Owner', 'email' => 'bandung@outstanding.test', 'password' => bcrypt('password'),
            'role' => 'owner_cabang', 'branch_id' => $this->branch->id, 'status' => 'active',
        ]);
    }

    /** PO → SO → LO chain, delivered, reconciled by the observer. */
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

        $lo->update(['status' => 'delivered']);

        return [$po, $so, $lo->fresh()];
    }

    #[Test]
    public function a_case_3_claim_is_listed_as_an_outstanding_obligation_with_its_source_po(): void
    {
        [$po] = $this->deliverChain(100, 100, 105, '003');

        $rows = OutstandingReconciliations::forBranch($this->branch->id);

        $this->assertCount(1, $rows);
        $this->assertSame('over_delivery_claim', $rows->first()->settlement_type);
        $this->assertTrue($rows->first()->applies_to_next_delivery);
        $this->assertSame($po->id, $rows->first()->source_purchase_order_id);

        $line = OutstandingReconciliations::line($rows->first());
        $this->assertStringContainsString('PO2026-003', $line);
        $this->assertStringContainsString('5 unit', $line);
        $this->assertStringContainsString('carry-forward dari PO2026-003', $line);

        $reference = OutstandingReconciliations::noteReference($this->branch->id, '3kg');
        $this->assertNotNull($reference);
        $this->assertStringContainsString('Referensi rekonsiliasi tabung:', $reference);
        $this->assertStringContainsString('PO2026-003', $reference);
        $this->assertStringContainsString('10.000', $reference);
    }

    #[Test]
    public function a_balanced_chain_has_nothing_outstanding_and_keeps_the_notes_untouched(): void
    {
        $this->deliverChain(100, 100, 100, '004');

        $this->assertCount(0, OutstandingReconciliations::forBranch($this->branch->id));
        $this->assertNull(OutstandingReconciliations::noteReference($this->branch->id));
        $this->assertSame('Catatan asli', OutstandingReconciliations::appendReference('Catatan asli', $this->branch->id));
    }

    #[Test]
    public function the_reference_is_appended_to_the_notes_idempotently(): void
    {
        $this->deliverChain(100, 90, 90, '005');

        $first = OutstandingReconciliations::appendReference('Catatan asli', $this->branch->id, '3kg');

        $this->assertStringStartsWith('Catatan asli', $first);
        $this->assertStringContainsString('Referensi rekonsiliasi tabung:', $first);
        $this->assertStringContainsString('PO2026-005', $first);
        $this->assertStringContainsString('unredeemed', strtolower($first));

        $this->assertSame($first, OutstandingReconciliations::appendReference($first, $this->branch->id, '3kg'));
    }


    #[Test]
    public function the_outstanding_api_is_pusat_only(): void
    {
        $this->deliverChain(100, 90, 90, '006');

        $this->actingAs($this->pusat, 'sanctum');
        $this->getJson("/api/v1/reconciliations/outstanding?branch_id={$this->branch->id}")
            ->assertOk()
            ->assertJsonPath('data.rows.0.settlement_type', 'unredeemed_credit')
            ->assertJsonPath('data.rows.0.purchase_order_number', 'PO2026-006')
            ->assertJsonPath('data.total_amount', 100000);

        $this->actingAs($this->branchOwner, 'sanctum');
        $this->getJson("/api/v1/reconciliations/outstanding?branch_id={$this->branch->id}")
            ->assertStatus(403);
    }

    #[Test]
    public function pusat_can_settle_an_obligation_with_the_next_loading_order(): void
    {
        [, , $lo] = $this->deliverChain(100, 90, 90, '007');

        $settlement = ReconciliationSettlement::sole();

        $this->actingAs($this->pusat, 'sanctum');
        $this->postJson("/api/v1/reconciliations/settlements/{$settlement->id}/settle", [
            'settled_by_type' => ReconciliationSettlement::DOCUMENT_LOADING_ORDER,
            'document_id'     => $lo->id,
            'notes'           => 'Dikirim pada LO berikutnya.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'settled')
            ->assertJsonPath('data.settled_by_id', $lo->id);

        $settlement->refresh();
        $this->assertSame(ReconciliationSettlement::STATUS_SETTLED, $settlement->status);
        $this->assertNotNull($settlement->settled_at);
        $this->assertSame($this->pusat->id, $settlement->settled_by_user_id);
        $this->assertStringContainsString('Dikirim pada LO berikutnya.', (string) $settlement->notes);

        // Nothing outstanding any more.
        $this->assertCount(0, OutstandingReconciliations::forBranch($this->branch->id));

        // Settling twice is refused, never overwritten.
        $this->postJson("/api/v1/reconciliations/settlements/{$settlement->id}/settle", [
            'settled_by_type' => ReconciliationSettlement::DOCUMENT_LOADING_ORDER,
            'document_id'     => $lo->id,
        ])->assertStatus(422);
    }

    #[Test]
    public function a_settlement_requires_an_existing_document_of_the_matching_type(): void
    {
        $this->deliverChain(100, 90, 90, '008');

        $settlement = ReconciliationSettlement::sole();

        $this->actingAs($this->pusat, 'sanctum');
        $this->postJson("/api/v1/reconciliations/settlements/{$settlement->id}/settle", [
            'settled_by_type' => ReconciliationSettlement::DOCUMENT_INVOICE,
            'document_id'     => 999999,
        ])->assertStatus(422);

        $this->assertSame(ReconciliationSettlement::STATUS_OPEN, $settlement->fresh()->status);
    }

    #[Test]
    public function pusat_can_void_an_obligation_and_branches_cannot(): void
    {
        $this->deliverChain(100, 100, 95, '009');

        $claim = ReconciliationSettlement::where('settlement_type', 'transportir_short_claim')->sole();

        $this->actingAs($this->branchOwner, 'sanctum');
        $this->postJson("/api/v1/reconciliations/settlements/{$claim->id}/void", ['reason' => 'x'])
            ->assertStatus(403);

        $this->actingAs($this->pusat, 'sanctum');
        $this->postJson("/api/v1/reconciliations/settlements/{$claim->id}/void", ['reason' => 'Sudah dibayar tunai'])
            ->assertOk()
            ->assertJsonPath('data.status', 'void');

        $claim->refresh();
        $this->assertSame(ReconciliationSettlement::STATUS_VOID, $claim->status);
        $this->assertStringContainsString('Sudah dibayar tunai', (string) $claim->notes);
    }

    #[Test]
    public function the_data_entry_warning_renders_on_the_loading_order_form(): void
    {
        $this->deliverChain(100, 90, 90, '010');

        $this->actingAs($this->pusat);

        Livewire::test(CreateLoadingOrder::class)
            ->fillForm([
                'destination_branch_id' => $this->branch->id,
                'cylinder_type'         => '3kg',
            ])
            ->assertSee('Rekonsiliasi Tabung — Saldo Terbuka')
            ->assertSee('PO2026-010');
    }

    /** T13-05: the list offers an explicit PO/SO/LO number lookup. */
    #[Test]
    public function the_reconciliation_list_filters_by_document_number(): void
    {
        $this->deliverChain(100, 90, 90, '013');
        $this->deliverChain(100, 100, 105, '014');

        $this->actingAs($this->pusat);

        $matching = CylinderReconciliation::query()
            ->where('purchase_order_id', DeliveryOrder::where('do_number', 'PO2026-014')->value('id'))
            ->sole();
        $other = CylinderReconciliation::query()
            ->where('purchase_order_id', DeliveryOrder::where('do_number', 'PO2026-013')->value('id'))
            ->sole();

        Livewire::test(ListReconciliations::class)
            ->filterTable('document_number', ['number' => 'SO2026-014'])
            ->assertCanSeeTableRecords([$matching])
            ->assertCanNotSeeTableRecords([$other]);
    }

    /** T13-05: the settlements relation manager renders and lists the obligations. */
    #[Test]
    public function the_settlements_relation_manager_renders(): void
    {
        $this->deliverChain(100, 90, 90, '015');

        $reconciliation = CylinderReconciliation::sole();

        $this->actingAs($this->pusat);

        Livewire::test(ReconciliationSettlementsRelationManager::class, [
            'ownerRecord' => $reconciliation,
            'pageClass'   => ViewReconciliation::class,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords($reconciliation->settlements);
    }

    /** T13-05: reconciliations are findable by any document number of the chain. */
    #[Test]
    public function reconciliations_can_be_looked_up_by_po_so_or_lo_number(): void
    {
        $this->deliverChain(100, 90, 90, '011');
        $this->deliverChain(100, 100, 105, '012');

        $this->actingAs($this->pusat, 'sanctum');

        $this->getJson('/api/v1/reconciliations?po_number=PO2026-011')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.purchase_order.do_number', 'PO2026-011');

        $this->getJson('/api/v1/reconciliations?so_number=SO2026-012')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.loading_order.do_number', 'LOT2026-012');

        // Partial LO number combined with the settlement status filter.
        $this->getJson('/api/v1/reconciliations?lo_number=LOT2026-012&settlement_status=open')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.case_type', 'case_3');

        $this->getJson('/api/v1/reconciliations?lo_number=nope')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }
}



