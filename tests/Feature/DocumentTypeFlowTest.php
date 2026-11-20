<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DeliveryOrder;
use App\Models\StockClose;
use App\Models\User;
use App\Filament\Resources\DeliveryOrderResource;
use App\Filament\Resources\PurchaseOrderResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DocumentTypeFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $pusat;
    private User $branchOwner;
    private Branch $mainBranch;
    private Branch $otherBranch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mainBranch = Branch::create([
            'code'   => 'PST',
            'name'   => 'SUM Pusat',
            'city'   => 'Jakarta',
            'status' => 'active',
        ]);

        $this->otherBranch = Branch::create([
            'code'   => 'BDG',
            'name'   => 'SUM Bandung',
            'city'   => 'Bandung',
            'status' => 'active',
        ]);

        $this->pusat = User::create([
            'name'      => 'Pusat User',
            'email'     => 'pusat@test.com',
            'password'  => bcrypt('password'),
            'role'      => 'owner_pusat',
            'branch_id' => $this->mainBranch->id,
            'status'    => 'active',
        ]);

        $this->branchOwner = User::create([
            'name'      => 'Branch Owner',
            'email'     => 'branch@test.com',
            'password'  => bcrypt('password'),
            'role'      => 'owner_cabang',
            'branch_id' => $this->otherBranch->id,
            'status'    => 'active',
        ]);
    }

    #[Test]
    public function pusat_can_create_sales_order_to_pertamina(): void
    {
        $this->actingAs($this->pusat, 'sanctum');

        $response = $this->postJson('/api/v1/sales-orders', [
            'do_number'        => 'SO2026-001',
            'order_date'       => '2026-08-10',
            'cylinder_type'    => '3kg',
            'quantity_ordered' => 100,
            'counterparty_name' => 'Pertamina Patra Niaga',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.document_type', 'so')
            ->assertJsonPath('data.counterparty_type', 'pertamina')
            ->assertJsonPath('data.counterparty_name', 'Pertamina Patra Niaga');
    }

    #[Test]
    public function branch_owner_cannot_create_sales_order(): void
    {
        $this->actingAs($this->branchOwner, 'sanctum');

        $response = $this->postJson('/api/v1/sales-orders', [
            'do_number'        => 'SO2026-002',
            'order_date'       => '2026-08-10',
            'cylinder_type'    => '3kg',
            'quantity_ordered' => 100,
        ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function branch_owner_can_create_purchase_order_to_main_branch(): void
    {
        $this->actingAs($this->branchOwner, 'sanctum');

        // Golden Rule: must submit stock close before creating PO
        StockClose::create([
            'branch_id'     => $this->otherBranch->id,
            'close_date'    => today(),
            'cylinder_type' => '3kg',
            'qty_full'      => 100,
            'qty_empty'     => 10,
            'qty_damaged'   => 0,
            'submitted_by'  => $this->branchOwner->id,
            'submitted_at'  => now(),
            'status'        => 'submitted',
        ]);

        $response = $this->postJson('/api/v1/purchase-orders', [
            'do_number'        => 'PO2026-001',
            'order_date'       => '2026-08-10',
            'cylinder_type'    => '3kg',
            'quantity_ordered' => 50,
            'destination_branch_id' => $this->mainBranch->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.document_type', 'po')
            ->assertJsonPath('data.counterparty_type', 'branch')
            ->assertJsonPath('data.destination_branch.id', $this->otherBranch->id)
            ->assertJsonPath('data.requested_by', $this->branchOwner->name);

        $this->assertDatabaseHas('delivery_orders', [
            'do_number' => 'PO2026-001',
            'origin_branch_id' => $this->mainBranch->id,
            'destination_branch_id' => $this->otherBranch->id,
            'requested_by' => $this->branchOwner->id,
        ]);
    }

    #[Test]
    public function pusat_can_create_a_purchase_order_for_a_selected_branch(): void
    {
        $this->actingAs($this->pusat, 'sanctum');

        $response = $this->postJson('/api/v1/purchase-orders', [
            'do_number'        => 'PO2026-002',
            'order_date'       => '2026-08-10',
            'cylinder_type'    => '3kg',
            'quantity_ordered' => 50,
            'destination_branch_id' => $this->otherBranch->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.document_type', 'po')
            ->assertJsonPath('data.destination_branch.id', $this->otherBranch->id)
            ->assertJsonPath('data.requested_by', $this->pusat->name);

        $this->assertDatabaseHas('delivery_orders', [
            'do_number' => 'PO2026-002',
            'origin_branch_id' => $this->mainBranch->id,
            'destination_branch_id' => $this->otherBranch->id,
            'requested_by' => $this->pusat->id,
            'counterparty_name' => $this->mainBranch->name,
        ]);
    }

    #[Test]
    public function hq_cannot_create_a_purchase_order_for_the_hq_branch_itself(): void
    {
        $this->actingAs($this->pusat, 'sanctum')
            ->postJson('/api/v1/purchase-orders', [
                'order_date' => '2026-08-10',
                'cylinder_type' => '3kg',
                'quantity_ordered' => 50,
                'destination_branch_id' => $this->mainBranch->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('destination_branch_id');
    }

    #[Test]
    public function regional_leader_cannot_create_purchase_orders(): void
    {
        $regionalLeader = User::create([
            'name'      => 'Regional Leader',
            'email'     => 'regional@test.com',
            'password'  => bcrypt('password'),
            'role'      => 'regional_leader',
            'branch_id' => $this->mainBranch->id,
            'status'    => 'active',
        ]);

        $this->actingAs($regionalLeader, 'sanctum');
        $this->assertFalse(PurchaseOrderResource::canCreate());

        $response = $this->postJson('/api/v1/purchase-orders', [
            'order_date'       => '2026-08-10',
            'cylinder_type'    => '3kg',
            'quantity_ordered' => 50,
            'destination_branch_id' => $this->otherBranch->id,
        ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function hq_must_select_an_active_destination_branch_for_a_purchase_order(): void
    {
        $response = $this->actingAs($this->pusat, 'sanctum')
            ->postJson('/api/v1/purchase-orders', [
                'order_date'       => '2026-08-10',
                'cylinder_type'    => '3kg',
                'quantity_ordered' => 50,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('destination_branch_id');
    }

    #[Test]
    public function delivery_order_menu_is_hidden_while_its_resource_access_remains(): void
    {
        $this->actingAs($this->pusat);

        $this->assertFalse(DeliveryOrderResource::shouldRegisterNavigation());
        $this->assertTrue(DeliveryOrderResource::canAccess());
        // N-GEN-7: legacy DOs are read-only history.
        $this->assertFalse(DeliveryOrderResource::canCreate());
        $this->assertTrue(PurchaseOrderResource::canCreate());
        $this->assertTrue(PurchaseOrderResource::shouldRegisterNavigation());

        $this->actingAs($this->branchOwner);
        $this->assertTrue(PurchaseOrderResource::canCreate());
    }

    #[Test]
    public function approved_so_does_not_create_do(): void
    {
        $so = DeliveryOrder::create([
            'do_number'          => 'SO2026-003',
            'document_type'      => 'so',
            'counterparty_type'  => 'pertamina',
            'counterparty_name'  => 'Pertamina',
            'origin_branch_id'   => $this->mainBranch->id,
            'cylinder_type'      => '3kg',
            'quantity_ordered'   => 100,
            'order_date'         => '2026-08-10',
            'requested_by'       => $this->pusat->id,
            'status'             => 'pending_approval',
        ]);

        $so->update([
            'status'      => 'approved',
            'approved_by' => $this->pusat->id,
            'approved_at' => now(),
        ]);

        // N-GEN-7: the DO flow is retired — approving an SO must not spawn a Delivery Order.
        $this->assertSame(1, DeliveryOrder::salesOrders()->count());
        $this->assertSame(0, DeliveryOrder::deliveryOrders()->count());
        $this->assertDatabaseMissing('delivery_orders', [
            'document_type' => 'do',
        ]);
    }

    #[Test]
    public function approved_po_does_not_create_do(): void
    {
        $po = DeliveryOrder::create([
            'do_number'          => 'PO2026-003',
            'document_type'      => 'po',
            'counterparty_type'  => 'branch',
            'counterparty_name'  => 'SUM Bandung',
            'origin_branch_id'   => $this->mainBranch->id,
            'destination_branch_id' => $this->otherBranch->id,
            'cylinder_type'      => '3kg',
            'quantity_ordered'   => 50,
            'order_date'         => '2026-08-10',
            'requested_by'       => $this->branchOwner->id,
            'status'             => 'pending_approval',
        ]);

        $po->update([
            'status'      => 'approved',
            'approved_by' => $this->pusat->id,
            'approved_at' => now(),
        ]);

        $this->assertSame(1, DeliveryOrder::purchaseOrders()->count());
        $this->assertSame(0, DeliveryOrder::deliveryOrders()->count());

        $notification = \Illuminate\Support\Facades\DB::table('notifications')
            ->where('notifiable_id', $this->branchOwner->id)
            ->orderByDesc('created_at')
            ->first();
        $this->assertStringContainsString('Track fulfillment on this PO.', json_decode($notification->data, true)['body']);
    }

    #[Test]
    public function delivery_order_index_defaults_to_do_only(): void
    {
        DeliveryOrder::create([
            'do_number'          => 'SO2026-004',
            'document_type'      => 'so',
            'counterparty_type'  => 'pertamina',
            'origin_branch_id'   => $this->mainBranch->id,
            'cylinder_type'      => '3kg',
            'quantity_ordered'   => 100,
            'order_date'         => '2026-08-10',
            'requested_by'       => $this->pusat->id,
            'status'             => 'draft',
        ]);

        DeliveryOrder::create([
            'do_number'          => 'DO2026-001',
            'document_type'      => 'do',
            'origin_branch_id'   => $this->mainBranch->id,
            'destination_branch_id' => $this->otherBranch->id,
            'cylinder_type'      => '3kg',
            'quantity_ordered'   => 50,
            'order_date'         => '2026-08-10',
            'requested_by'       => $this->branchOwner->id,
            'status'             => 'draft',
        ]);

        $this->actingAs($this->pusat, 'sanctum');

        $response = $this->getJson('/api/v1/delivery-orders');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.document_type', 'do');
    }

    #[Test]
    public function branch_user_cannot_access_delivery_orders_api(): void
    {
        $this->actingAs($this->branchOwner, 'sanctum');

        $response = $this->getJson('/api/v1/delivery-orders');

        $response->assertStatus(403);
    }

    #[Test]
    public function branch_user_only_sees_their_own_purchase_orders(): void
    {
        // Create a PO for the other branch (owned by branchOwner)
        DeliveryOrder::create([
            'do_number'          => 'PO2026-004',
            'document_type'      => 'po',
            'counterparty_type'  => 'branch',
            'counterparty_name'  => 'SUM Bandung',
            'origin_branch_id'   => $this->mainBranch->id,
            'destination_branch_id' => $this->otherBranch->id,
            'cylinder_type'      => '3kg',
            'quantity_ordered'   => 50,
            'order_date'         => '2026-08-10',
            'requested_by'       => $this->branchOwner->id,
            'status'             => 'draft',
        ]);

        // Create a PO for a different branch (should NOT be visible to branchOwner)
        $anotherBranch = Branch::create([
            'code'   => 'SRG',
            'name'   => 'SUM Surabaya',
            'city'   => 'Surabaya',
            'status' => 'active',
        ]);

        DeliveryOrder::create([
            'do_number'          => 'PO2026-005',
            'document_type'      => 'po',
            'counterparty_type'  => 'branch',
            'counterparty_name'  => 'SUM Surabaya',
            'origin_branch_id'   => $this->mainBranch->id,
            'destination_branch_id' => $anotherBranch->id,
            'cylinder_type'      => '3kg',
            'quantity_ordered'   => 30,
            'order_date'         => '2026-08-10',
            'requested_by'       => $this->pusat->id,
            'status'             => 'draft',
        ]);

        $this->actingAs($this->branchOwner, 'sanctum');

        $response = $this->getJson('/api/v1/purchase-orders');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.do_number', 'PO2026-004');
    }

    #[Test]
    public function delivery_order_write_endpoints_are_gone(): void
    {
        $legacyDo = DeliveryOrder::create([
            'do_number'             => 'DO2026-001',
            'document_type'         => 'do',
            'origin_branch_id'      => $this->mainBranch->id,
            'destination_branch_id' => $this->otherBranch->id,
            'cylinder_type'         => '3kg',
            'quantity_ordered'      => 50,
            'order_date'            => '2026-08-10',
            'requested_by'          => $this->pusat->id,
            'status'                => 'draft',
        ]);

        $this->actingAs($this->pusat, 'sanctum');

        // N-GEN-7: only index/show remain — no create, no workflow transitions.
        $this->postJson('/api/v1/delivery-orders', [])->assertStatus(405);
        $this->postJson("/api/v1/delivery-orders/{$legacyDo->id}/submit")->assertStatus(404);
        $this->postJson("/api/v1/delivery-orders/{$legacyDo->id}/approve")->assertStatus(404);
        $this->postJson("/api/v1/delivery-orders/{$legacyDo->id}/mark-in-transit")->assertStatus(404);
        $this->deleteJson("/api/v1/delivery-orders/{$legacyDo->id}")->assertStatus(405);

        $this->getJson("/api/v1/delivery-orders/{$legacyDo->id}")->assertStatus(200);
    }

    #[Test]
    public function dashboard_counts_po_and_lo_but_not_legacy_do(): void
    {
        // A legacy DO left in an active status must not inflate the live counters.
        DeliveryOrder::create([
            'do_number'             => 'DO2026-010',
            'document_type'         => 'do',
            'origin_branch_id'      => $this->mainBranch->id,
            'destination_branch_id' => $this->otherBranch->id,
            'cylinder_type'         => '3kg',
            'quantity_ordered'      => 50,
            'order_date'            => '2026-08-10',
            'requested_by'          => $this->branchOwner->id,
            'status'                => 'approved',
        ]);

        DeliveryOrder::create([
            'do_number'             => 'PO2026-010',
            'document_type'         => 'po',
            'counterparty_type'     => 'branch',
            'origin_branch_id'      => $this->mainBranch->id,
            'destination_branch_id' => $this->otherBranch->id,
            'cylinder_type'         => '3kg',
            'quantity_ordered'      => 50,
            'order_date'            => '2026-08-10',
            'requested_by'          => $this->branchOwner->id,
            'status'                => 'approved',
        ]);

        DeliveryOrder::create([
            'do_number'             => 'LO2026-010',
            'document_type'         => 'lo',
            'origin_branch_id'      => $this->mainBranch->id,
            'destination_branch_id' => $this->otherBranch->id,
            'cylinder_type'         => '3kg',
            'quantity_ordered'      => 50,
            'order_date'            => '2026-08-10',
            'requested_by'          => $this->branchOwner->id,
            'status'                => 'approved',
        ]);

        $this->actingAs($this->pusat, 'sanctum');

        $this->getJson('/api/v1/dashboard/main')
            ->assertStatus(200)
            ->assertJsonPath('data.stats.active_do_count', 2);
    }
}
