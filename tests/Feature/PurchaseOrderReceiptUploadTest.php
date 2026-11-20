<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseOrderResource\Pages\ListPurchaseOrders;
use App\Models\Branch;
use App\Models\DeliveryOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * D10 (2026-11-20): the proof of delivery lives on the PO now that the DO flow is
 * retired. Guard: HQ approvers OR the receiving (destination) branch.
 */
class PurchaseOrderReceiptUploadTest extends TestCase
{
    use RefreshDatabase;

    private Branch $hqBranch;
    private Branch $branch;
    private Branch $otherBranch;
    private User $pusat;
    private User $branchUser;
    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hqBranch = Branch::create([
            'code' => 'PST', 'name' => 'SUM Pusat', 'city' => 'Jakarta', 'status' => 'active',
        ]);
        $this->branch = Branch::create([
            'code' => 'BDG', 'name' => 'SUM Bandung', 'city' => 'Bandung', 'status' => 'active',
        ]);
        $this->otherBranch = Branch::create([
            'code' => 'SRG', 'name' => 'SUM Surabaya', 'city' => 'Surabaya', 'status' => 'active',
        ]);

        $this->pusat = User::create([
            'name' => 'Pusat', 'email' => 'pusat@po-receipt.test', 'password' => bcrypt('password'),
            'role' => 'owner_pusat', 'branch_id' => $this->hqBranch->id, 'status' => 'active',
        ]);
        $this->branchUser = User::create([
            'name' => 'Bandung Owner', 'email' => 'bandung@po-receipt.test', 'password' => bcrypt('password'),
            'role' => 'owner_cabang', 'branch_id' => $this->branch->id, 'status' => 'active',
        ]);
        $this->otherUser = User::create([
            'name' => 'Surabaya Owner', 'email' => 'surabaya@po-receipt.test', 'password' => bcrypt('password'),
            'role' => 'owner_cabang', 'branch_id' => $this->otherBranch->id, 'status' => 'active',
        ]);
    }

    private function makePo(string $status = 'in_transit'): DeliveryOrder
    {
        return DeliveryOrder::create([
            'do_number'             => 'PO2026-901',
            'document_type'         => 'po',
            'counterparty_type'     => 'branch',
            'counterparty_name'     => $this->hqBranch->name,
            'origin_branch_id'      => $this->hqBranch->id,
            'destination_branch_id' => $this->branch->id,
            'cylinder_type'         => '12kg',
            'quantity_ordered'      => 50,
            'order_date'            => '2026-11-20',
            'requested_by'          => $this->branchUser->id,
            'status'                => $status,
        ]);
    }

    private function upload(DeliveryOrder $po, User $user, ?UploadedFile $file = null)
    {
        $file ??= UploadedFile::fake()->create('surat-jalan.pdf', 120, 'application/pdf');

        return $this->actingAs($user, 'sanctum')
            ->post(
                "/api/v1/purchase-orders/{$po->id}/receipt",
                ['receipt' => $file],
                ['Accept' => 'application/json'],
            );
    }

    #[Test]
    public function hq_approver_can_upload_the_po_receipt(): void
    {
        Storage::fake('public');

        $po = $this->makePo();

        $response = $this->upload($po, $this->pusat)
            ->assertOk()
            ->assertJsonPath('data.document_type', 'po');

        $path = $po->fresh()->receipt_path;

        $this->assertNotNull($path);
        $this->assertStringContainsString('po-receipts', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertStringContainsString('po-receipts', (string) $response->json('data.receipt_url'));
    }

    #[Test]
    public function the_receiving_branch_can_upload_the_po_receipt(): void
    {
        Storage::fake('public');

        $po = $this->makePo();

        $this->upload($po, $this->branchUser)->assertOk();

        $this->assertNotNull($po->fresh()->receipt_path);
    }

    #[Test]
    public function another_branch_cannot_upload_the_po_receipt(): void
    {
        Storage::fake('public');

        $po = $this->makePo();

        $this->upload($po, $this->otherUser)->assertStatus(403);

        $this->assertNull($po->fresh()->receipt_path);
    }

    #[Test]
    public function a_draft_po_has_no_receipt_yet(): void
    {
        Storage::fake('public');

        $po = $this->makePo('draft');

        $this->upload($po, $this->pusat)->assertStatus(422);

        $this->assertNull($po->fresh()->receipt_path);
    }

    #[Test]
    public function a_delivered_po_accepts_the_receipt_as_well(): void
    {
        Storage::fake('public');

        $po = $this->makePo('delivered');

        $this->upload($po, $this->pusat)->assertOk();

        $this->assertNotNull($po->fresh()->receipt_path);
    }

    #[Test]
    public function the_receipt_must_be_an_image_or_a_pdf(): void
    {
        Storage::fake('public');

        $po = $this->makePo();

        $this->upload($po, $this->pusat, UploadedFile::fake()->create('catatan.txt', 5, 'text/plain'))
            ->assertStatus(422);

        $this->assertNull($po->fresh()->receipt_path);
    }

    #[Test]
    public function a_non_po_document_is_rejected(): void
    {
        Storage::fake('public');

        $lo = DeliveryOrder::create([
            'do_number'             => 'LOT2026-901',
            'document_type'         => 'lo',
            'lo_kind'               => 'transportir',
            'po_number'             => 'PO2026-901',
            'origin_branch_id'      => $this->hqBranch->id,
            'destination_branch_id' => $this->branch->id,
            'cylinder_type'         => '12kg',
            'quantity_ordered'      => 10,
            'order_date'            => '2026-11-20',
            'requested_by'          => $this->pusat->id,
            'status'                => 'delivered',
        ]);

        $this->upload($lo, $this->pusat)->assertStatus(422);
    }

    #[Test]
    public function the_panel_exposes_the_upload_action_to_the_receiving_branch(): void
    {
        Storage::fake('public');

        $po = $this->makePo();

        $this->actingAs($this->branchUser);

        Livewire::test(ListPurchaseOrders::class)
            ->assertTableActionVisible('upload_receipt', $po);
    }
}
