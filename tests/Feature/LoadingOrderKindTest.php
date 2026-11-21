<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DeliveryOrder;
use App\Models\Expedition;
use App\Models\Transportir;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BALI-11 / BALI-12: Transportir and Expedition Loading Orders are two distinct
 * documents sharing `delivery_orders` (document_type = 'lo' + lo_kind).
 *
 * Reference chain: PO → LO Transportir (LOT) → LO Expedisi (LOE).
 */
class LoadingOrderKindTest extends TestCase
{
    use RefreshDatabase;

    private Branch $mainBranch;

    private Branch $otherBranch;

    private User $pusat;

    private Transportir $transportir;

    private Expedition $expedition;

    private DeliveryOrder $po;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mainBranch = Branch::create([
            'code' => 'PST',
            'name' => 'SUM Pusat',
            'city' => 'Jakarta',
            'status' => 'active',
        ]);

        $this->otherBranch = Branch::create([
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

        $this->expedition = Expedition::create([
            'name' => 'Ekspedisi Bali',
            'code' => 'EXP-01',
            'status' => 'active',
        ]);

        $this->po = DeliveryOrder::create([
            'do_number' => 'PO2026-001',
            'document_type' => 'po',
            'counterparty_type' => 'branch',
            'counterparty_name' => 'SUM Bandung',
            'origin_branch_id' => $this->mainBranch->id,
            'destination_branch_id' => $this->otherBranch->id,
            'cylinder_type' => '12kg',
            'quantity_ordered' => 100,
            'order_date' => today()->toDateString(),
            'requested_by' => $this->pusat->id,
            'status' => 'approved',
        ]);
    }

    private function transportirPayload(array $overrides = []): array
    {
        return array_merge([
            'order_date' => today()->toDateString(),
            'cylinder_type' => '12kg',
            'quantity_ordered' => 100,
            'destination_branch_id' => $this->otherBranch->id,
            'po_number' => $this->po->do_number,
            'transportir_id' => $this->transportir->id,
        ], $overrides);
    }

    private function expeditionPayload(?DeliveryOrder $parent, array $overrides = []): array
    {
        return array_merge([
            'lo_kind' => 'expedition',
            'order_date' => today()->toDateString(),
            'cylinder_type' => '12kg',
            'quantity_ordered' => 100,
            'destination_branch_id' => $this->otherBranch->id,
            'transportir_lo_number' => $parent?->do_number,
            'expedition_id' => $this->expedition->id,
            'container_number' => 'CONT-2026-001',
            'expedition_cost' => 15000,
        ], $overrides);
    }

    private function createTransportirLo(): DeliveryOrder
    {
        return DeliveryOrder::create([
            'do_number' => 'LOT2026-001',
            'document_type' => 'lo',
            'lo_kind' => 'transportir',
            'origin_branch_id' => $this->mainBranch->id,
            'destination_branch_id' => $this->otherBranch->id,
            'po_number' => $this->po->do_number,
            'transportir_id' => $this->transportir->id,
            'cylinder_type' => '12kg',
            'quantity_ordered' => 100,
            'order_date' => today()->toDateString(),
            'requested_by' => $this->pusat->id,
            'status' => 'draft',
        ]);
    }

    #[Test]
    public function transportir_lo_is_created_in_its_own_lot_series(): void
    {
        $this->actingAs($this->pusat, 'sanctum');

        $response = $this->postJson('/api/v1/loading-orders', $this->transportirPayload());

        $response->assertCreated()
            ->assertJsonPath('data.document_type', 'lo')
            ->assertJsonPath('data.lo_kind', 'transportir')
            ->assertJsonPath('data.po_number', 'PO2026-001')
            ->assertJsonPath('data.cost_borne_by', 'transporter')
            ->assertJsonPath('data.destination_branch.id', $this->otherBranch->id);

        $this->assertStringStartsWith('LOT'.now()->year.'-', $response->json('data.do_number'));
    }

    #[Test]
    public function transportir_lo_requires_a_source_po_and_a_destination_branch(): void
    {
        $this->actingAs($this->pusat, 'sanctum');

        $this->postJson('/api/v1/loading-orders', $this->transportirPayload(['po_number' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('po_number');

        $this->postJson('/api/v1/loading-orders', $this->transportirPayload(['destination_branch_id' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('destination_branch_id');

        $this->postJson('/api/v1/loading-orders', $this->transportirPayload(['transportir_id' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('transportir_id');
    }

    #[Test]
    public function expedition_lo_requires_container_parent_lo_and_delivery_fee(): void
    {
        $this->actingAs($this->pusat, 'sanctum');
        $parent = $this->createTransportirLo();

        $this->postJson('/api/v1/loading-orders', $this->expeditionPayload($parent, ['container_number' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('container_number');

        $this->postJson('/api/v1/loading-orders', $this->expeditionPayload($parent, ['transportir_lo_number' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('transportir_lo_number');

        $this->postJson('/api/v1/loading-orders', $this->expeditionPayload($parent, ['expedition_cost' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('expedition_cost');

        $this->postJson('/api/v1/loading-orders', $this->expeditionPayload($parent, ['destination_branch_id' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('destination_branch_id');
    }

    #[Test]
    public function expedition_lo_is_created_in_its_own_loe_series_with_hq_cost(): void
    {
        $this->actingAs($this->pusat, 'sanctum');
        $parent = $this->createTransportirLo();

        $response = $this->postJson('/api/v1/loading-orders', $this->expeditionPayload($parent));

        $response->assertCreated()
            ->assertJsonPath('data.lo_kind', 'expedition')
            ->assertJsonPath('data.transportir_lo_number', 'LOT2026-001')
            ->assertJsonPath('data.container_number', 'CONT-2026-001')
            ->assertJsonPath('data.cost_borne_by', 'sum')
            ->assertJsonPath('data.expedition_cost', 15000);

        $this->assertStringStartsWith('LOE'.now()->year.'-', $response->json('data.do_number'));

        $created = DeliveryOrder::where('do_number', $response->json('data.do_number'))->firstOrFail();
        $this->assertTrue($created->isExpeditionLoadingOrder());
        $this->assertTrue($created->bearsExpeditionCost());
        $this->assertSame(15000.0, $created->expeditionFee());
        $this->assertSame($parent->id, $created->transportirLo?->id);
    }

    #[Test]
    public function expedition_lo_rejects_a_parent_that_is_not_a_transportir_lo(): void
    {
        $this->actingAs($this->pusat, 'sanctum');

        $this->postJson('/api/v1/loading-orders', $this->expeditionPayload(null, [
            'transportir_lo_number' => $this->po->do_number,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('transportir_lo_number');
    }

    #[Test]
    public function transportir_lo_never_keeps_an_expedition_cost(): void
    {
        $this->actingAs($this->pusat, 'sanctum');

        $response = $this->postJson('/api/v1/loading-orders', $this->transportirPayload([
            'expedition_cost' => 99999,
            'cost_borne_by' => 'sum',
        ]));

        $response->assertCreated();

        $lot = DeliveryOrder::where('do_number', $response->json('data.do_number'))->firstOrFail();
        $this->assertNull($lot->expedition_cost);
        $this->assertSame('transporter', $lot->cost_borne_by);
        $this->assertSame(0.0, $lot->expeditionFee());
    }

    #[Test]
    public function submit_blocks_an_expedition_lo_without_a_container(): void
    {
        $this->actingAs($this->pusat, 'sanctum');
        $parent = $this->createTransportirLo();

        $loe = DeliveryOrder::create([
            'do_number' => 'LOE2026-001',
            'document_type' => 'lo',
            'lo_kind' => 'expedition',
            'origin_branch_id' => $this->mainBranch->id,
            'destination_branch_id' => $this->otherBranch->id,
            'transportir_lo_number' => $parent->do_number,
            'expedition_id' => $this->expedition->id,
            'cost_borne_by' => 'sum',
            'expedition_cost' => 15000,
            'cylinder_type' => '12kg',
            'quantity_ordered' => 100,
            'order_date' => today()->toDateString(),
            'requested_by' => $this->pusat->id,
            'status' => 'draft',
        ]);

        $this->postJson("/api/v1/loading-orders/{$loe->id}/submit")
            ->assertStatus(422);

        $loe->update(['container_number' => 'CONT-2026-999']);

        $this->postJson("/api/v1/loading-orders/{$loe->id}/submit")->assertOk();
    }

    #[Test]
    public function scopes_partition_the_two_loading_order_kinds(): void
    {
        $parent = $this->createTransportirLo();

        DeliveryOrder::create([
            'do_number' => 'LOE2026-001',
            'document_type' => 'lo',
            'lo_kind' => 'expedition',
            'origin_branch_id' => $this->mainBranch->id,
            'destination_branch_id' => $this->otherBranch->id,
            'transportir_lo_number' => $parent->do_number,
            'cylinder_type' => '12kg',
            'quantity_ordered' => 100,
            'order_date' => today()->toDateString(),
            'requested_by' => $this->pusat->id,
            'status' => 'draft',
        ]);

        $this->assertSame(1, DeliveryOrder::transportirLoadingOrders()->count());
        $this->assertSame(1, DeliveryOrder::expeditionLoadingOrders()->count());
        $this->assertSame(2, DeliveryOrder::loadingOrders()->count());
        $this->assertSame(1, $parent->expeditionLos()->count());
    }
}
