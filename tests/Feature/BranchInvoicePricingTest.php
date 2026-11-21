<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DeliveryOrder;
use App\Models\Invoice;
use App\Models\LpgPrice;
use App\Models\Transportir;
use App\Models\TransportirRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BALI-14: invoices snapshot the transportir rate and the expedition delivery
 * fee of the linked LO, and only add the fee to the total when configured.
 */
class BranchInvoicePricingTest extends TestCase
{
    use RefreshDatabase;

    private Branch $mainBranch;

    private Branch $branch;

    private User $pusat;

    private Transportir $transportir;

    private DeliveryOrder $loe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mainBranch = Branch::create([
            'code' => 'PST',
            'name' => 'SUM Pusat',
            'city' => 'Jakarta',
            'status' => 'active',
        ]);

        $this->branch = Branch::create([
            'code' => 'BDG',
            'name' => 'SUM Bandung',
            'city' => 'Bandung',
            'status' => 'active',
        ]);

        $this->pusat = User::create([
            'name' => 'Pusat User',
            'email' => 'pusat@test.com',
            'password' => bcrypt('password'),
            'role' => 'owner_pusat',
            'branch_id' => $this->mainBranch->id,
            'status' => 'active',
        ]);

        $this->transportir = Transportir::create([
            'name' => 'PT Raja Sumber Cahaya',
            'code' => 'TRP-01',
            'status' => 'active',
        ]);

        LpgPrice::create([
            'branch_id' => $this->branch->id,
            'cylinder_type' => '12kg',
            'purchase_price' => 20000,
            'selling_price' => 30000,
            'effective_date' => today()->toDateString(),
            'created_by' => $this->pusat->id,
        ]);

        TransportirRate::create([
            'transportir_id' => $this->transportir->id,
            'cylinder_type' => '12kg',
            'rate' => 5000,
            'effective_date' => today()->toDateString(),
            'created_by' => $this->pusat->id,
        ]);

        $this->loe = DeliveryOrder::create([
            'do_number' => 'LOE2026-001',
            'document_type' => 'lo',
            'lo_kind' => 'expedition',
            'origin_branch_id' => $this->mainBranch->id,
            'destination_branch_id' => $this->branch->id,
            'transportir_id' => $this->transportir->id,
            'cost_borne_by' => 'sum',
            'expedition_cost' => 5000,
            'container_number' => 'CONT-2026-001',
            'cylinder_type' => '12kg',
            'quantity_ordered' => 10,
            'order_date' => today()->toDateString(),
            'requested_by' => $this->pusat->id,
            'status' => 'approved',
        ]);
    }

    private function invoicePayload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'cylinder_type' => '12kg',
            'quantity' => 10,
            'issue_date' => today()->toDateString(),
            'due_date' => today()->addDays(30)->toDateString(),
        ], $overrides);
    }

    #[Test]
    public function invoice_snapshots_the_linked_lo_costs_without_charging_the_fee_by_default(): void
    {
        // Default: HQ absorbs the delivery fee (today's behaviour).
        $this->assertFalse((bool) config('app.expedition_fee_charged_to_buyer'));

        $this->actingAs($this->pusat, 'sanctum');

        $this->postJson('/api/v1/invoices', $this->invoicePayload([
            'loading_order_number' => $this->loe->do_number,
        ]))->assertCreated();

        $invoice = Invoice::latest('id')->firstOrFail();

        $this->assertSame($this->loe->do_number, $invoice->loading_order_number);
        $this->assertSame(5000.0, (float) $invoice->transportir_rate_at_sale);
        $this->assertSame(5000.0, (float) $invoice->expedition_cost_at_sale);
        $this->assertSame(0.0, (float) $invoice->expedition_fee_amount);
        $this->assertSame(300000.0, (float) $invoice->total_amount);
    }

    #[Test]
    public function delivery_fee_is_added_to_the_total_when_configured(): void
    {
        config(['app.expedition_fee_charged_to_buyer' => true]);

        $this->actingAs($this->pusat, 'sanctum');

        $this->postJson('/api/v1/invoices', $this->invoicePayload([
            'loading_order_number' => $this->loe->do_number,
        ]))->assertCreated();

        $invoice = Invoice::latest('id')->firstOrFail();

        $this->assertSame(5000.0, (float) $invoice->expedition_fee_amount);
        $this->assertSame(305000.0, (float) $invoice->total_amount);
    }

    #[Test]
    public function invoices_without_a_loading_order_keep_todays_pricing(): void
    {
        config(['app.expedition_fee_charged_to_buyer' => true]);

        $this->actingAs($this->pusat, 'sanctum');

        $this->postJson('/api/v1/invoices', $this->invoicePayload())->assertCreated();

        $invoice = Invoice::latest('id')->firstOrFail();

        $this->assertNull($invoice->loading_order_number);
        $this->assertNull($invoice->transportir_rate_at_sale);
        $this->assertNull($invoice->expedition_cost_at_sale);
        $this->assertSame(0.0, (float) $invoice->expedition_fee_amount);
        $this->assertSame(300000.0, (float) $invoice->total_amount);
    }

    #[Test]
    public function branch_cost_preview_returns_the_full_breakdown(): void
    {
        $this->actingAs($this->pusat, 'sanctum');

        $response = $this->getJson('/api/v1/prices/branch-cost-preview?'.http_build_query([
            'branch_id' => $this->branch->id,
            'cylinder_type' => '12kg',
            'quantity' => 10,
            'transportir_id' => $this->transportir->id,
            'expedition_cost' => 5000,
        ]));

        $response->assertOk()
            ->assertJsonPath('data.hpp_per_cylinder', 25000)
            ->assertJsonPath('data.gross_total', 300000)
            ->assertJsonPath('data.transportir_total', 50000)
            ->assertJsonPath('data.delivery_fee', 5000)
            ->assertJsonPath('data.net_after_costs', 45000);
    }

    #[Test]
    public function branch_cost_preview_uses_the_loading_order_when_given(): void
    {
        $this->actingAs($this->pusat, 'sanctum');

        $response = $this->getJson('/api/v1/prices/branch-cost-preview?'.http_build_query([
            'loading_order_number' => $this->loe->do_number,
            'cylinder_type' => '12kg',
        ]));

        $response->assertOk()
            ->assertJsonPath('data.quantity', 10)
            ->assertJsonPath('data.delivery_fee', 5000)
            ->assertJsonPath('data.branch_id', $this->branch->id);
    }
}
