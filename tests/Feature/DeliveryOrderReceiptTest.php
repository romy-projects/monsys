<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DeliveryOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeliveryOrderReceiptTest extends TestCase
{
    use RefreshDatabase;

    private User $pusat;
    private User $branchOwner;
    private User $branchStaff;
    private User $otherBranchOwner;
    private Branch $mainBranch;
    private Branch $destinationBranch;
    private Branch $thirdBranch;
    private DeliveryOrder $deliveredDo;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->mainBranch = Branch::create([
            'code'   => 'PST',
            'name'   => 'SUM Pusat',
            'city'   => 'Jakarta',
            'status' => 'active',
        ]);

        $this->destinationBranch = Branch::create([
            'code'   => 'BDG',
            'name'   => 'SUM Bandung',
            'city'   => 'Bandung',
            'status' => 'active',
        ]);

        $this->thirdBranch = Branch::create([
            'code'   => 'SRG',
            'name'   => 'SUM Surabaya',
            'city'   => 'Surabaya',
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
            'name'      => 'Bandung Owner',
            'email'     => 'bandung@test.com',
            'password'  => bcrypt('password'),
            'role'      => 'owner_cabang',
            'branch_id' => $this->destinationBranch->id,
            'status'    => 'active',
        ]);

        $this->branchStaff = User::create([
            'name'      => 'Bandung Staff',
            'email'     => 'bandung-staff@test.com',
            'password'  => bcrypt('password'),
            'role'      => 'staff_gudang',
            'branch_id' => $this->destinationBranch->id,
            'status'    => 'active',
        ]);

        $this->otherBranchOwner = User::create([
            'name'      => 'Surabaya Owner',
            'email'     => 'surabaya@test.com',
            'password'  => bcrypt('password'),
            'role'      => 'owner_cabang',
            'branch_id' => $this->thirdBranch->id,
            'status'    => 'active',
        ]);

        $this->deliveredDo = $this->makeOrder('DO2026-001', 'delivered');
    }

    private function makeOrder(string $doNumber, string $status): DeliveryOrder
    {
        return DeliveryOrder::create([
            'do_number'             => $doNumber,
            'document_type'         => 'do',
            'counterparty_type'     => 'branch',
            'counterparty_name'     => 'SUM Bandung',
            'origin_branch_id'      => $this->mainBranch->id,
            'destination_branch_id' => $this->destinationBranch->id,
            'cylinder_type'         => '3kg',
            'quantity_ordered'      => 50,
            'order_date'            => '2026-08-10',
            'requested_by'          => $this->pusat->id,
            'status'                => $status,
        ]);
    }

    /** @test */
    public function an_approver_can_upload_a_receipt_and_receives_a_public_url(): void
    {
        $this->actingAs($this->pusat, 'sanctum');

        $response = $this->postJson("/api/v1/delivery-orders/{$this->deliveredDo->id}/receipt", [
            'receipt' => UploadedFile::fake()->image('signed-receipt.jpg'),
        ]);

        $response->assertStatus(200);

        $path = $this->deliveredDo->fresh()->receipt_path;
        $this->assertNotNull($path);

        Storage::disk('public')->assertExists($path);
        $this->assertStringContainsString('do-receipts', $response->json('data.receipt_url'));
    }

    /** @test */
    public function the_destination_branch_can_upload_its_own_receipt(): void
    {
        $this->actingAs($this->branchOwner, 'sanctum');

        $this->postJson("/api/v1/delivery-orders/{$this->deliveredDo->id}/receipt", [
            'receipt' => UploadedFile::fake()->image('penerimaan.jpg'),
        ])->assertStatus(200);
    }

    /** @test */
    public function the_destination_branch_warehouse_staff_can_upload_a_receipt(): void
    {
        $this->actingAs($this->branchStaff, 'sanctum');

        $this->postJson("/api/v1/delivery-orders/{$this->deliveredDo->id}/receipt", [
            'receipt' => UploadedFile::fake()->image('terima.jpg'),
        ])->assertStatus(200);
    }

    /** @test */
    public function a_user_from_another_branch_cannot_upload_a_receipt(): void
    {
        $this->actingAs($this->otherBranchOwner, 'sanctum');

        $this->postJson("/api/v1/delivery-orders/{$this->deliveredDo->id}/receipt", [
            'receipt' => UploadedFile::fake()->image('wrong-branch.jpg'),
        ])->assertStatus(403);

        $this->assertNull($this->deliveredDo->fresh()->receipt_path);
    }

    /** @test */
    public function a_receipt_cannot_be_uploaded_for_a_draft_order(): void
    {
        $draft = $this->makeOrder('DO2026-002', 'draft');

        $this->actingAs($this->pusat, 'sanctum');

        $this->postJson("/api/v1/delivery-orders/{$draft->id}/receipt", [
            'receipt' => UploadedFile::fake()->image('too-early.jpg'),
        ])->assertStatus(422);
    }

    /** @test */
    public function a_receipt_file_is_required(): void
    {
        $this->actingAs($this->pusat, 'sanctum');

        $this->postJson("/api/v1/delivery-orders/{$this->deliveredDo->id}/receipt", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('receipt');
    }

    /** @test */
    public function a_receipt_rejects_disallowed_file_types(): void
    {
        $this->actingAs($this->pusat, 'sanctum');

        $this->postJson("/api/v1/delivery-orders/{$this->deliveredDo->id}/receipt", [
            'receipt' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ])->assertStatus(422)
            ->assertJsonValidationErrors('receipt');
    }

    /** @test */
    public function a_receipt_rejects_files_larger_than_two_megabytes(): void
    {
        $this->actingAs($this->pusat, 'sanctum');

        $this->postJson("/api/v1/delivery-orders/{$this->deliveredDo->id}/receipt", [
            'receipt' => UploadedFile::fake()->create('big.pdf', 3000, 'application/pdf'),
        ])->assertStatus(422)
            ->assertJsonValidationErrors('receipt');
    }
}
