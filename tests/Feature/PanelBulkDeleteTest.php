<?php

namespace Tests\Feature;

use App\Filament\Resources\CylinderCirculationResource\Pages\ListCylinderCirculations;
use App\Filament\Resources\StockMutationResource;
use App\Filament\Resources\StockMutationResource\Pages\ListStockMutations;
use App\Models\Branch;
use App\Models\CylinderCirculation;
use App\Models\DeliveryOrder;
use App\Models\Role;
use App\Models\StockMutation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The delete bulk action used to be authorized with the (still empty) selection:
 * `$records->every(...)` ran on null while the table rendered and took the whole
 * list page down. Authorization now gates on the permission alone and each
 * record is checked in `using()`, which always has the selection.
 */
class PanelBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'code' => 'PST',
            'name' => 'SUM Pusat',
            'city' => 'Jakarta',
            'status' => 'active',
        ]);

        $this->superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'bulk-delete-admin@example.test',
            'password' => bcrypt('password'),
            'role' => 'owner_pusat',
            'branch_id' => null,
            'status' => 'active',
        ]);
        $this->superAdmin->accessRole()->associate(Role::query()->where('name', 'super_admin')->firstOrFail());
        $this->superAdmin->save();

        $this->actingAs($this->superAdmin->fresh());
    }

    #[Test]
    public function the_cylinder_circulation_list_renders_without_a_selection(): void
    {
        Livewire::test(ListCylinderCirculations::class)->assertOk();
    }

    #[Test]
    public function bulk_delete_removes_manual_rows_and_keeps_purchase_order_rows(): void
    {
        $manual = $this->makeCirculation();
        $fromPurchaseOrder = $this->makeCirculation($this->makePurchaseOrder());

        Livewire::test(ListCylinderCirculations::class)
            ->callTableBulkAction('delete', [$manual->id, $fromPurchaseOrder->id]);

        $this->assertDatabaseMissing('cylinder_circulations', ['id' => $manual->id]);
        $this->assertDatabaseHas('cylinder_circulations', ['id' => $fromPurchaseOrder->id]);
    }

    #[Test]
    public function the_stock_mutation_list_renders_without_a_selection(): void
    {
        // This is the screen that first surfaced the crash while logging in: the
        // bulk action was authorized against a null selection while the table
        // rendered. The catalog grants `stock_mutations` view/create only, so the
        // delete action stays hidden — rendering must not crash either way.
        $this->makeMutation();
        $this->makeMutation($this->makePurchaseOrder());

        Livewire::test(ListStockMutations::class)->assertOk();
    }

    #[Test]
    public function the_stock_mutation_delete_action_is_limited_to_manual_rows(): void
    {
        // Rows fed by a purchase order are owned by the document and never
        // deleted from here; the guard runs per record, not on the selection.
        $this->assertFalse(StockMutationResource::canDelete($this->makeMutation($this->makePurchaseOrder())));
    }

    private function makeMutation(?DeliveryOrder $purchaseOrder = null): StockMutation
    {
        return StockMutation::create([
            'branch_id' => $this->branch->id,
            'mutation_date' => '2026-10-10',
            'mutation_type' => 'in',
            'cylinder_type' => '12kg',
            'quantity' => 5,
            'created_by' => $this->superAdmin->id,
            'purchase_order_id' => $purchaseOrder?->id,
        ]);
    }

    private function makeCirculation(?DeliveryOrder $purchaseOrder = null): CylinderCirculation
    {
        return CylinderCirculation::create([
            'branch_id' => $this->branch->id,
            'transaction_date' => '2026-10-10',
            'transaction_type' => 'pembelian',
            'cylinder_type' => '12kg',
            'quantity' => 5,
            'created_by' => $this->superAdmin->id,
            'purchase_order_id' => $purchaseOrder?->id,
        ]);
    }

    private function makePurchaseOrder(): DeliveryOrder
    {
        return DeliveryOrder::create([
            'do_number' => 'PO-BULK-DELETE-001',
            'document_type' => 'po',
            'counterparty_type' => 'branch',
            'counterparty_name' => $this->branch->name,
            'origin_branch_id' => $this->branch->id,
            'destination_branch_id' => $this->branch->id,
            'cylinder_type' => '12kg',
            'quantity_ordered' => 5,
            'order_date' => '2026-10-10',
            'requested_by' => $this->superAdmin->id,
            'status' => 'in_transit',
        ]);
    }
}
