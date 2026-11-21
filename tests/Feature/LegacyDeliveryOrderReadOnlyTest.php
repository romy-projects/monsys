<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DeliveryOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * N-GEN-7: the Delivery Order (document_type = 'do') flow is retired.
 *
 * Legacy DO rows stay readable through GET /api/v1/delivery-orders (mobilex and
 * historical reports still open them), but every write path is gone.
 */
class LegacyDeliveryOrderReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    private User $pusat;

    private User $branchOwner;

    private DeliveryOrder $legacyDo;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $mainBranch = Branch::create([
            'code' => 'PST',
            'name' => 'SUM Pusat',
            'city' => 'Jakarta',
            'status' => 'active',
        ]);

        $destinationBranch = Branch::create([
            'code' => 'BDG',
            'name' => 'SUM Bandung',
            'city' => 'Bandung',
            'status' => 'active',
        ]);

        $this->pusat = User::create([
            'name' => 'Pusat User',
            'email' => 'pusat-legacy@test.com',
            'password' => bcrypt('password'),
            'role' => 'owner_pusat',
            'branch_id' => $mainBranch->id,
            'status' => 'active',
        ]);

        $this->branchOwner = User::create([
            'name' => 'Bandung Owner',
            'email' => 'bandung-legacy@test.com',
            'password' => bcrypt('password'),
            'role' => 'owner_cabang',
            'branch_id' => $destinationBranch->id,
            'status' => 'active',
        ]);

        $this->legacyDo = DeliveryOrder::create([
            'do_number' => 'DO2026-001',
            'document_type' => 'do',
            'counterparty_type' => 'branch',
            'counterparty_name' => 'SUM Bandung',
            'origin_branch_id' => $mainBranch->id,
            'destination_branch_id' => $destinationBranch->id,
            'cylinder_type' => '3kg',
            'quantity_ordered' => 50,
            'order_date' => '2026-08-10',
            'requested_by' => $this->pusat->id,
            'receipt_path' => 'do-receipts/signed-receipt.pdf',
            'status' => 'delivered',
        ]);
    }

    #[Test]
    public function show_exposes_the_archived_receipt_url(): void
    {
        $this->actingAs($this->pusat, 'sanctum');

        $this->getJson("/api/v1/delivery-orders/{$this->legacyDo->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.document_type', 'do')
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.receipt_url', asset('storage/do-receipts/signed-receipt.pdf'));
    }

    #[Test]
    public function receipt_upload_is_no_longer_available(): void
    {
        $this->actingAs($this->pusat, 'sanctum');

        $this->postJson("/api/v1/delivery-orders/{$this->legacyDo->id}/receipt", [
            'receipt' => UploadedFile::fake()->image('signed-receipt.jpg'),
        ])->assertStatus(404);
    }

    #[Test]
    public function create_update_delete_and_workflow_endpoints_are_gone(): void
    {
        $this->actingAs($this->pusat, 'sanctum');

        $this->postJson('/api/v1/delivery-orders', [
            'cylinder_type' => '3kg',
            'quantity_ordered' => 10,
            'order_date' => '2026-08-10',
        ])->assertStatus(405);

        $this->putJson("/api/v1/delivery-orders/{$this->legacyDo->id}", [
            'quantity_ordered' => 99,
        ])->assertStatus(405);

        $this->deleteJson("/api/v1/delivery-orders/{$this->legacyDo->id}")->assertStatus(405);

        foreach (['submit', 'approve', 'mark-in-transit', 'receive', 'cancel'] as $action) {
            $this->postJson("/api/v1/delivery-orders/{$this->legacyDo->id}/{$action}")
                ->assertStatus(404);
        }

        $this->assertDatabaseHas('delivery_orders', [
            'id' => $this->legacyDo->id,
            'quantity_ordered' => 50,
            'status' => 'delivered',
        ]);
    }

    #[Test]
    public function the_branch_side_of_a_legacy_do_stays_readable(): void
    {
        $this->actingAs($this->branchOwner, 'sanctum');

        $this->getJson("/api/v1/delivery-orders/{$this->legacyDo->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.do_number', 'DO2026-001');
    }
}
