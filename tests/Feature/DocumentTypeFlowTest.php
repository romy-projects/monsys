<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DeliveryOrder;
use App\Models\StockClose;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    /** @test */
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

    /** @test */
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

    /** @test */
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
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.document_type', 'po')
            ->assertJsonPath('data.counterparty_type', 'branch')
            ->assertJsonPath('data.destination_branch.id', $this->otherBranch->id);
    }

    /** @test */
    public function pusat_cannot_create_purchase_order(): void
    {
        $this->actingAs($this->pusat, 'sanctum');

        $response = $this->postJson('/api/v1/purchase-orders', [
            'do_number'        => 'PO2026-002',
            'order_date'       => '2026-08-10',
            'cylinder_type'    => '3kg',
            'quantity_ordered' => 50,
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function approved_so_auto_creates_do(): void
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

        $this->assertDatabaseHas('delivery_orders', [
            'document_type' => 'do',
            'so_number'     => 'SO2026-003',
        ]);
    }

    /** @test */
    public function approved_po_auto_creates_do(): void
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

        $this->assertDatabaseHas('delivery_orders', [
            'document_type' => 'do',
            'po_number'     => 'PO2026-003',
        ]);
    }

    /** @test */
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

    /** @test */
    public function branch_user_cannot_access_delivery_orders_api(): void
    {
        $this->actingAs($this->branchOwner, 'sanctum');

        $response = $this->getJson('/api/v1/delivery-orders');

        $response->assertStatus(403);
    }

    /** @test */
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
}
