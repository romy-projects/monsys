<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\DeliveryOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DeliveryOrderModelTest extends TestCase
{
    use RefreshDatabase;

    private Branch $mainBranch;
    private Branch $otherBranch;
    private User $user;

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

        $this->user = User::create([
            'name'      => 'Test User',
            'email'     => 'test@test.com',
            'password'  => bcrypt('password'),
            'role'      => 'owner_pusat',
            'branch_id' => $this->mainBranch->id,
            'status'    => 'active',
        ]);
    }

    private function makeOrder(string $documentType, string $doNumber): DeliveryOrder
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

    #[Test]
    public function it_can_scope_documents_by_type(): void
    {
        $this->makeOrder('so', 'SO2026-001');
        $this->makeOrder('do', 'DO2026-001');
        $this->makeOrder('lo', 'LO2026-001');
        $this->makeOrder('po', 'PO2026-001');

        $this->assertSame(1, DeliveryOrder::salesOrders()->count());
        $this->assertSame(1, DeliveryOrder::deliveryOrders()->count());
        $this->assertSame(1, DeliveryOrder::loadingOrders()->count());
        $this->assertSame(1, DeliveryOrder::purchaseOrders()->count());
    }

    #[Test]
    public function it_returns_the_correct_document_type_label(): void
    {
        $so = $this->makeOrder('so', 'SO2026-002');
        $do = $this->makeOrder('do', 'DO2026-002');
        $lo = $this->makeOrder('lo', 'LO2026-002');
        $po = $this->makeOrder('po', 'PO2026-002');

        $this->assertSame('Sales Order', $so->document_type_label);
        $this->assertSame('Delivery Order', $do->document_type_label);
        $this->assertSame('Loading Order', $lo->document_type_label);
        $this->assertSame('Purchase Order', $po->document_type_label);
    }

    #[Test]
    public function it_returns_the_correct_counterparty_label(): void
    {
        $so = $this->makeOrder('so', 'SO2026-003');
        $po = $this->makeOrder('po', 'PO2026-003');

        $this->assertSame('Pertamina', $so->counterparty_label);
        $this->assertSame('SUM Bandung', $po->counterparty_label);
    }

    #[Test]
    public function it_uses_pertamina_default_when_counterparty_name_not_set(): void
    {
        $so = DeliveryOrder::create([
            'do_number'          => 'SO2026-004',
            'document_type'      => 'so',
            'counterparty_type'  => 'pertamina',
            'origin_branch_id'   => $this->mainBranch->id,
            'cylinder_type'      => '3kg',
            'quantity_ordered'   => 100,
            'order_date'         => '2026-08-10',
            'requested_by'       => $this->user->id,
            'status'             => 'draft',
        ]);

        $this->assertSame('Pertamina', $so->counterparty_label);
    }
}
