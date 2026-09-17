<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DeliveryOrder;
use App\Models\StockClose;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentNumberAllocationTest extends TestCase
{
    use RefreshDatabase;

    private User $pusat;
    private User $branchOwner;
    private Branch $mainBranch;
    private Branch $otherBranch;
    private int $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->year = (int) date('Y');

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

        // Golden Rule: a branch must submit today's stock close before creating a PO
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
    }

    /** @test */
    public function branch_can_create_a_purchase_order_without_sending_a_number(): void
    {
        $this->actingAs($this->branchOwner, 'sanctum');

        $response = $this->postJson('/api/v1/purchase-orders', [
            'order_date'       => '2026-08-10',
            'cylinder_type'    => '3kg',
            'quantity_ordered' => 50,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.do_number', "PO{$this->year}-001")
            ->assertJsonPath('data.document_type', 'po')
            ->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('delivery_orders', [
            'do_number'     => "PO{$this->year}-001",
            'document_type' => 'po',
        ]);
    }

    /** @test */
    public function repeated_creates_get_distinct_numbers_and_never_collide(): void
    {
        $this->actingAs($this->branchOwner, 'sanctum');

        $numbers = [];

        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/v1/purchase-orders', [
                'order_date'       => '2026-08-10',
                'cylinder_type'    => '3kg',
                'quantity_ordered' => 10 + $i,
            ]);

            $response->assertStatus(201);

            $numbers[] = $response->json('data.do_number');
        }

        $this->assertCount(10, array_unique($numbers), 'All allocated numbers must be unique');
        $this->assertSame("PO{$this->year}-001", $numbers[0]);
        $this->assertSame("PO{$this->year}-010", $numbers[9]);
        $this->assertSame(10, DeliveryOrder::where('document_type', 'po')->count());
    }

    /** @test */
    public function an_explicitly_supplied_number_is_still_honoured(): void
    {
        $this->actingAs($this->branchOwner, 'sanctum');

        $response = $this->postJson('/api/v1/purchase-orders', [
            'do_number'        => 'PO2026-777',
            'order_date'       => '2026-08-10',
            'cylinder_type'    => '3kg',
            'quantity_ordered' => 50,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.do_number', 'PO2026-777');
    }

    /** @test */
    public function pusat_can_create_a_sales_order_without_sending_a_number(): void
    {
        $this->actingAs($this->pusat, 'sanctum');

        $response = $this->postJson('/api/v1/sales-orders', [
            'order_date'        => '2026-08-10',
            'cylinder_type'     => '3kg',
            'quantity_ordered'  => 100,
            'counterparty_name' => 'Pertamina Patra Niaga',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.do_number', "SO{$this->year}-001")
            ->assertJsonPath('data.document_type', 'so');
    }

    /** @test */
    public function invoice_number_is_allocated_server_side(): void
    {
        $this->actingAs($this->pusat, 'sanctum');

        $response = $this->postJson('/api/v1/invoices', [
            'branch_id'     => $this->mainBranch->id,
            'cylinder_type' => '3kg',
            'quantity'      => 10,
            'unit_price'    => 20000,
            'issue_date'    => '2026-08-10',
            'due_date'      => '2026-08-20',
        ]);

        $response->assertStatus(201);

        $this->assertSame("INV{$this->year}-001", $response->json('data.invoice_number'));
    }

    /** @test */
    public function auto_created_do_from_an_approved_so_gets_a_server_number(): void
    {
        $so = DeliveryOrder::create([
            'do_number'         => 'SO2026-900',
            'document_type'     => 'so',
            'counterparty_type' => 'pertamina',
            'counterparty_name' => 'Pertamina',
            'origin_branch_id'  => $this->mainBranch->id,
            'cylinder_type'     => '3kg',
            'quantity_ordered'  => 100,
            'order_date'        => '2026-08-10',
            'requested_by'      => $this->pusat->id,
            'status'            => 'pending_approval',
        ]);

        $so->update([
            'status'      => 'approved',
            'approved_by' => $this->pusat->id,
            'approved_at' => now(),
        ]);

        $this->assertDatabaseHas('delivery_orders', [
            'document_type' => 'do',
            'so_number'     => 'SO2026-900',
            'do_number'     => "DO{$this->year}-001",
        ]);
    }
}
