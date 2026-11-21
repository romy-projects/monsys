<?php

namespace Tests\Feature;

use App\Filament\Resources\CylinderCirculationResource;
use App\Filament\Resources\StockItemResource;
use App\Filament\Resources\StockMutationResource;
use App\Models\Branch;
use App\Models\CylinderCirculation;
use App\Models\DeliveryOrder;
use App\Models\StockItem;
use App\Models\StockMutation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PurchaseOrderReceiptFlowTest extends TestCase
{
    use RefreshDatabase;

    private Branch $hqBranch;

    private Branch $requestingBranch;

    private User $hqUser;

    private User $branchUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hqBranch = Branch::create([
            'code' => 'PST',
            'name' => 'SUM Pusat',
            'city' => 'Jakarta',
            'status' => 'active',
        ]);
        $this->requestingBranch = Branch::create([
            'code' => 'BDG',
            'name' => 'SUM Bandung',
            'city' => 'Bandung',
            'status' => 'active',
        ]);

        $this->hqUser = User::create([
            'name' => 'HQ User',
            'email' => 'hq-receipt@test.com',
            'password' => bcrypt('password'),
            'role' => 'owner_pusat',
            'branch_id' => $this->hqBranch->id,
            'status' => 'active',
        ]);
        $this->branchUser = User::create([
            'name' => 'Branch User',
            'email' => 'branch-receipt@test.com',
            'password' => bcrypt('password'),
            'role' => 'owner_cabang',
            'branch_id' => $this->requestingBranch->id,
            'status' => 'active',
        ]);
    }

    #[Test]
    public function po_receipt_uses_received_quantity_and_is_idempotent(): void
    {
        $receivedDate = '2026-10-17';
        $po = $this->makeInTransitPo();

        $hqStock = StockItem::create([
            'branch_id' => $this->hqBranch->id,
            'cylinder_type' => '12kg',
            'qty_full' => 100,
            'qty_empty' => 4,
            'qty_damaged' => 0,
            'recorded_at' => $receivedDate,
        ]);
        $branchStock = StockItem::create([
            'branch_id' => $this->requestingBranch->id,
            'cylinder_type' => '12kg',
            'qty_full' => 10,
            'qty_empty' => 5,
            'qty_damaged' => 1,
            'recorded_at' => $receivedDate,
        ]);

        $this->actingAs($this->branchUser, 'sanctum')
            ->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
                'quantity_received' => 17,
                'received_date' => $receivedDate,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered');

        $this->assertSame(2, StockMutation::withoutGlobalScopes()->where('purchase_order_id', $po->id)->count());
        $this->assertDatabaseHas('stock_mutations', [
            'purchase_order_id' => $po->id,
            'branch_id' => $this->hqBranch->id,
            'mutation_type' => 'out',
            'quantity' => 17,
        ]);
        $this->assertDatabaseHas('stock_mutations', [
            'purchase_order_id' => $po->id,
            'branch_id' => $this->requestingBranch->id,
            'mutation_type' => 'in',
            'quantity' => 17,
        ]);
        $this->assertSame(83, StockItem::withoutGlobalScopes()->findOrFail($hqStock->id)->qty_full);
        $this->assertSame(27, $branchStock->fresh()->qty_full);

        $circulation = CylinderCirculation::withoutGlobalScopes()->where('purchase_order_id', $po->id)->firstOrFail();
        $this->assertSame($this->requestingBranch->id, $circulation->branch_id);
        $this->assertSame('kirim', $circulation->transaction_type);
        $this->assertSame('debit', $circulation->direction);
        $this->assertSame(17, $circulation->quantity);
        $this->assertFalse(StockMutationResource::canEdit(StockMutation::where('purchase_order_id', $po->id)->firstOrFail()));
        $this->assertFalse(StockMutationResource::canDelete(StockMutation::where('purchase_order_id', $po->id)->firstOrFail()));
        $this->assertFalse(CylinderCirculationResource::canEdit($circulation));
        $this->assertFalse(CylinderCirculationResource::canDelete($circulation));

        $this->actingAs($this->branchUser, 'sanctum')
            ->putJson("/api/v1/cylinder-circulations/{$circulation->id}", ['quantity' => 33])
            ->assertForbidden();
        $this->deleteJson("/api/v1/cylinder-circulations/{$circulation->id}")
            ->assertForbidden();

        // A repeated delivery-status handling pass must not replay any stock side effects.
        $po->update(['shipment_status' => 'at_transportir_warehouse']);
        $po->update(['shipment_status' => 'delivered_to_destination']);

        $this->assertSame(2, StockMutation::withoutGlobalScopes()->where('purchase_order_id', $po->id)->count());
        $this->assertSame(1, CylinderCirculation::withoutGlobalScopes()->where('purchase_order_id', $po->id)->count());
        $this->assertSame(83, StockItem::withoutGlobalScopes()->findOrFail($hqStock->id)->qty_full);
        $this->assertSame(27, $branchStock->fresh()->qty_full);

        $this->actingAs($this->hqUser, 'sanctum')
            ->getJson('/api/v1/cylinder-circulations/summary')
            ->assertOk()
            ->assertJsonPath('data.rows.0.balances.12kg', 17);
    }

    #[Test]
    public function a_zero_received_quantity_is_not_replaced_by_the_ordered_quantity(): void
    {
        $po = $this->makeInTransitPo();

        $this->actingAs($this->branchUser, 'sanctum')
            ->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
                'quantity_received' => 0,
                'received_date' => '2026-10-17',
            ])
            ->assertOk();

        $this->assertSame(
            [0, 0],
            StockMutation::withoutGlobalScopes()
                ->where('purchase_order_id', $po->id)
                ->orderBy('mutation_type')
                ->pluck('quantity')
                ->all()
        );
        $this->assertSame(0, CylinderCirculation::where('purchase_order_id', $po->id)->value('quantity'));
    }

    #[Test]
    public function hq_can_view_branch_ready_stock_but_cannot_manage_it(): void
    {
        $stock = StockItem::create([
            'branch_id' => $this->requestingBranch->id,
            'cylinder_type' => '3kg',
            'qty_full' => 40,
            'qty_empty' => 12,
            'qty_damaged' => 2,
            'recorded_at' => today(),
        ]);

        $this->actingAs($this->hqUser);
        $this->assertFalse(StockItemResource::canCreate());
        $this->assertFalse(StockItemResource::canEdit($stock));
        $this->assertFalse(StockItemResource::canDelete($stock));

        $this->actingAs($this->branchUser);
        $this->assertTrue(StockItemResource::canCreate());
        $this->assertTrue(StockItemResource::canEdit($stock));
        $this->assertTrue(StockItemResource::canDelete($stock));

        $this->actingAs($this->hqUser, 'sanctum')
            ->getJson("/api/v1/stock?branch_id={$this->requestingBranch->id}")
            ->assertOk()
            ->assertJsonPath('data.0.qty_full', 40)
            ->assertJsonPath('data.0.qty_empty', 12);

        $this->postJson('/api/v1/import/stock_items')
            ->assertNotFound();
    }

    private function makeInTransitPo(): DeliveryOrder
    {
        return DeliveryOrder::create([
            'do_number' => 'PO-RECEIPT-001',
            'document_type' => 'po',
            'counterparty_type' => 'branch',
            'counterparty_name' => $this->hqBranch->name,
            'origin_branch_id' => $this->hqBranch->id,
            'destination_branch_id' => $this->requestingBranch->id,
            'cylinder_type' => '12kg',
            'quantity_ordered' => 50,
            'order_date' => '2026-10-10',
            'requested_by' => $this->hqUser->id,
            'status' => 'in_transit',
        ]);
    }
}
