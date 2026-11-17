<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DailySale;
use App\Models\DeliveryOrder;
use App\Models\Invoice;
use App\Models\LpgPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LpgPriceTransactionFlowTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $branchOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'code' => 'BDG',
            'name' => 'SUM Bandung',
            'city' => 'Bandung',
            'status' => 'active',
        ]);

        $this->branchOwner = User::create([
            'name' => 'Branch Owner',
            'email' => 'branch@test.com',
            'password' => bcrypt('password'),
            'role' => 'owner_cabang',
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);
    }

    public function test_sales_and_invoices_store_the_exact_effective_price_reference(): void
    {
        $date = now()->toDateString();
        $firstPrice = $this->createPrice($date, 10000, 20000);
        $this->assertDatabaseHas('lpg_prices', [
            'id' => $firstPrice->id,
            'branch_id' => $this->branch->id,
            'cylinder_type' => '3kg',
        ]);
        $this->assertSame($firstPrice->id, LpgPrice::priceAtDate('3kg', $date, $this->branch->id)?->id);

        $this->actingAs($this->branchOwner, 'sanctum');

        $firstSale = $this->postJson('/api/v1/sales', [
            'cylinder_type' => '3kg',
            'buyer_type' => 'retail',
            'quantity' => 2,
            'sale_date' => $date,
        ])->assertCreated()
            ->assertJsonPath('data.lpg_price_id', $firstPrice->id)
            ->assertJsonPath('data.selling_price', 20000)
            ->assertJsonPath('data.purchase_price_at_sale', 10000);

        $correctionPrice = $this->createPrice($date, 12000, 21000);

        $secondSale = $this->postJson('/api/v1/sales', [
            'cylinder_type' => '3kg',
            'buyer_type' => 'retail',
            'quantity' => 1,
            'sale_date' => $date,
        ])->assertCreated()
            ->assertJsonPath('data.lpg_price_id', $correctionPrice->id)
            ->assertJsonPath('data.selling_price', 21000);

        $invoice = $this->postJson('/api/v1/invoices', [
            'branch_id' => $this->branch->id,
            'cylinder_type' => '3kg',
            'quantity' => 3,
            'issue_date' => $date,
            'due_date' => now()->addDays(30)->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('data.lpg_price_id', $correctionPrice->id)
            ->assertJsonPath('data.unit_price', '21000.00')
            ->assertJsonPath('data.total_amount', '63000.00');

        $sales = DailySale::query()->whereDate('sale_date', $date)->get();

        $this->assertSame(
            32000.0,
            LpgPrice::totalHppForSales($sales, $this->branch->id),
        );
        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->json('data.id'),
            'lpg_price_id' => $correctionPrice->id,
        ]);
        $this->assertSame($firstPrice->id, $firstSale->json('data.lpg_price_id'));
        $this->assertSame($correctionPrice->id, $secondSale->json('data.lpg_price_id'));
        $this->assertSame(1, Invoice::count());
        $this->assertSame(2, DailySale::count());
    }

    public function test_transaction_cannot_be_saved_when_no_effective_price_exists(): void
    {
        $this->actingAs($this->branchOwner, 'sanctum');

        $this->postJson('/api/v1/sales', [
            'cylinder_type' => '3kg',
            'buyer_type' => 'retail',
            'quantity' => 1,
            'sale_date' => now()->toDateString(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('lpg_price_id');

        $this->assertDatabaseCount('daily_sales', 0);
    }

    public function test_branch_price_change_notifies_admin_and_only_the_affected_branch(): void
    {
        $admin = User::create([
            'name' => 'Pusat Admin',
            'email' => 'pusat@test.com',
            'password' => bcrypt('password'),
            'role' => 'owner_pusat',
            'status' => 'active',
        ]);
        $otherBranch = Branch::create([
            'code' => 'SBY',
            'name' => 'SUM Surabaya',
            'city' => 'Surabaya',
            'status' => 'active',
        ]);
        $otherOwner = User::create([
            'name' => 'Other Branch Owner',
            'email' => 'other@test.com',
            'password' => bcrypt('password'),
            'role' => 'owner_cabang',
            'branch_id' => $otherBranch->id,
            'status' => 'active',
        ]);

        $this->createPrice(now()->toDateString(), 10000, 20000);

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $this->branchOwner->id,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $otherOwner->id,
        ]);
    }

    public function test_branch_invoice_uses_destination_branch_price_not_invoice_branch_price(): void
    {
        $mainBranch = Branch::create([
            'code' => 'PST',
            'name' => 'SUM Pusat',
            'city' => 'Jakarta',
            'status' => 'active',
        ]);
        $admin = User::create([
            'name' => 'Pusat Admin',
            'email' => 'pusat@test.com',
            'password' => bcrypt('password'),
            'role' => 'owner_pusat',
            'branch_id' => $mainBranch->id,
            'status' => 'active',
        ]);
        $sourcePrice = LpgPrice::create([
            'branch_id' => $mainBranch->id,
            'cylinder_type' => '3kg',
            'purchase_price' => 10000,
            'selling_price' => 18000,
            'effective_date' => now()->subDay()->toDateString(),
            'created_by' => $admin->id,
        ]);
        $destinationPrice = LpgPrice::create([
            'branch_id' => $this->branch->id,
            'cylinder_type' => '3kg',
            'purchase_price' => 12000,
            'selling_price' => 22000,
            'effective_date' => now()->subDay()->toDateString(),
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin, 'sanctum');

        $response = $this->postJson('/api/v1/invoices', [
            'branch_id' => $mainBranch->id,
            'reference_type' => 'branch',
            'reference_id' => $this->branch->id,
            'cylinder_type' => '3kg',
            'quantity' => 2,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('data.lpg_price_id', $destinationPrice->id)
            ->assertJsonPath('data.unit_price', '22000.00')
            ->assertJsonPath('data.total_amount', '44000.00');

        $this->assertNotSame($sourcePrice->id, $response->json('data.lpg_price_id'));
        $this->assertSame(1, Invoice::count());
        $this->assertSame(0, DailySale::count());
    }

    public function test_invoice_with_no_destination_price_notifies_admin_and_is_not_saved(): void
    {
        $mainBranch = Branch::create([
            'code' => 'PST',
            'name' => 'SUM Pusat',
            'city' => 'Jakarta',
            'status' => 'active',
        ]);
        $admin = User::create([
            'name' => 'Pusat Admin',
            'email' => 'pusat@test.com',
            'password' => bcrypt('password'),
            'role' => 'owner_pusat',
            'branch_id' => $mainBranch->id,
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/invoices', [
                'branch_id' => $mainBranch->id,
                'reference_type' => 'branch',
                'reference_id' => $this->branch->id,
                'cylinder_type' => '12kg',
                'quantity' => 1,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lpg_price_id');

        $this->assertSame(0, Invoice::count());
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $admin->id,
        ]);
    }

    public function test_linked_po_sets_lpg_price_effective_date_to_fourteen_days_after_system_entry(): void
    {
        $po = DeliveryOrder::create([
            'do_number' => 'PO2026-001',
            'document_type' => 'po',
            'counterparty_type' => 'branch',
            'counterparty_name' => $this->branch->name,
            'origin_branch_id' => $this->branch->id,
            'destination_branch_id' => $this->branch->id,
            'cylinder_type' => '3kg',
            'quantity_ordered' => 10,
            'order_date' => '2026-12-01',
            'requested_by' => $this->branchOwner->id,
            'status' => 'draft',
        ]);
        $enteredAt = now()->parse('2026-12-25 16:30:00');
        $po->forceFill(['created_at' => $enteredAt])->saveQuietly();

        $price = LpgPrice::create([
            'branch_id' => $this->branch->id,
            'purchase_order_id' => $po->id,
            'cylinder_type' => '3kg',
            'purchase_price' => 10000,
            'selling_price' => 20000,
            'effective_date' => '2026-01-01',
            'created_by' => $this->branchOwner->id,
        ]);

        $this->assertSame('2027-01-08', $price->effective_date->toDateString());
        $this->assertSame($po->id, $price->purchaseOrder->id);
    }

    private function createPrice(string $effectiveDate, int $purchasePrice, int $sellingPrice): LpgPrice
    {
        return LpgPrice::create([
            'branch_id' => $this->branch->id,
            'cylinder_type' => '3kg',
            'purchase_price' => $purchasePrice,
            'selling_price' => $sellingPrice,
            'effective_date' => $effectiveDate,
            'created_by' => $this->branchOwner->id,
        ]);
    }
}
