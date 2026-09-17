<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\DeliveryOrder;
use App\Models\User;
use App\Services\DocumentNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentNumberServiceTest extends TestCase
{
    use RefreshDatabase;

    private DocumentNumberService $service;
    private Branch $mainBranch;
    private Branch $otherBranch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(DocumentNumberService::class);

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

        $this->user = User::create([
            'name'      => 'Test User',
            'email'     => 'test@test.com',
            'password'  => bcrypt('password'),
            'role'      => 'owner_pusat',
            'branch_id' => $this->mainBranch->id,
            'status'    => 'active',
        ]);
    }

    private function makeOrder(string $doNumber, string $documentType = 'po'): DeliveryOrder
    {
        return DeliveryOrder::create([
            'do_number'          => $doNumber,
            'document_type'      => $documentType,
            'counterparty_type'  => $documentType === 'po' ? 'branch' : 'pertamina',
            'counterparty_name'  => $documentType === 'po' ? 'SUM Bandung' : 'Pertamina',
            'origin_branch_id'   => $this->mainBranch->id,
            'destination_branch_id' => $documentType === 'po' ? $this->otherBranch->id : null,
            'cylinder_type'      => '3kg',
            'quantity_ordered'   => 100,
            'order_date'         => '2026-08-10',
            'requested_by'       => $this->user->id,
            'status'             => 'draft',
        ]);
    }

    /** @test */
    public function migration_seeds_a_counter_row_for_every_document_type(): void
    {
        $year = (int) date('Y');

        foreach (['so', 'do', 'lo', 'po', 'inv'] as $type) {
            $this->assertDatabaseHas('document_sequences', [
                'document_type' => $type,
                'year'          => $year,
            ]);
        }
    }

    /** @test */
    public function it_allocates_consecutive_numbers(): void
    {
        $this->assertSame('PO2026-001', $this->service->next('po', 2026));
        $this->assertSame('PO2026-002', $this->service->next('po', 2026));
        $this->assertSame('PO2026-003', $this->service->next('po', 2026));
    }

    /** @test */
    public function counters_are_independent_per_document_type_and_year(): void
    {
        $this->assertSame('SO2026-001', $this->service->next('so', 2026));
        $this->assertSame('PO2026-001', $this->service->next('po', 2026));
        $this->assertSame('DO2026-001', $this->service->next('do', 2026));
        $this->assertSame('LO2026-001', $this->service->next('lo', 2026));
        $this->assertSame('INV2026-001', $this->service->next('inv', 2026));

        // Next year restarts at 001 and does not disturb this year's counter.
        $this->assertSame('PO2027-001', $this->service->next('po', 2027));
        $this->assertSame('PO2026-002', $this->service->next('po', 2026));
    }

    /** @test */
    public function it_skips_numbers_already_held_by_an_existing_document(): void
    {
        // Counter says 0, but PO2026-001 is already taken — it must never be re-issued.
        $this->makeOrder('PO2026-001');

        $this->assertSame('PO2026-002', $this->service->next('po', 2026));
    }

    /** @test */
    public function it_skips_numbers_held_by_soft_deleted_documents(): void
    {
        $year = (int) date('Y');

        // A soft-deleted row keeps its do_number in the database, so the unique index
        // still rejects it — the allocator must not hand it out again.
        $this->makeOrder("PO{$year}-001")->delete();

        $this->assertSame("PO{$year}-002", $this->service->next('po', $year));
    }
}
