<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DeliveryOrder;
use App\Models\LpgPrice;
use App\Models\User;
use App\Services\CylinderReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T12-08b (2026-11-20): a delivered LO that leaves a discrepancy notifies HQ
 * (`owner_pusat`) and the branch that owns the PO — their own numbers only — and
 * nobody else. Safe reprocessing (BALI-22) never re-notifies.
 */
class ReconciliationNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $pusat;

    private User $regional;

    private User $branchOwner;

    private User $branchStaff;

    private User $otherBranchOwner;

    private Branch $mainBranch;

    private Branch $branch;

    private Branch $otherBranch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mainBranch = Branch::create([
            'code' => 'PST', 'name' => 'SUM Pusat', 'city' => 'Jakarta', 'status' => 'active',
        ]);
        $this->branch = Branch::create([
            'code' => 'BDG', 'name' => 'SUM Bandung', 'city' => 'Bandung', 'status' => 'active',
        ]);
        $this->otherBranch = Branch::create([
            'code' => 'SBY', 'name' => 'SUM Surabaya', 'city' => 'Surabaya', 'status' => 'active',
        ]);

        $this->pusat = User::create([
            'name' => 'Pusat', 'email' => 'pusat@notify.test', 'password' => bcrypt('password'),
            'role' => 'owner_pusat', 'branch_id' => $this->mainBranch->id, 'status' => 'active',
        ]);
        $this->regional = User::create([
            'name' => 'Regional', 'email' => 'regional@notify.test', 'password' => bcrypt('password'),
            'role' => 'regional_leader', 'branch_id' => $this->mainBranch->id, 'status' => 'active',
        ]);
        $this->branchOwner = User::create([
            'name' => 'Bandung Owner', 'email' => 'bandung@notify.test', 'password' => bcrypt('password'),
            'role' => 'owner_cabang', 'branch_id' => $this->branch->id, 'status' => 'active',
        ]);
        $this->branchStaff = User::create([
            'name' => 'Bandung Staff', 'email' => 'gudang@notify.test', 'password' => bcrypt('password'),
            'role' => 'staff_gudang', 'branch_id' => $this->branch->id, 'status' => 'active',
        ]);
        $this->otherBranchOwner = User::create([
            'name' => 'Surabaya Owner', 'email' => 'surabaya@notify.test', 'password' => bcrypt('password'),
            'role' => 'owner_cabang', 'branch_id' => $this->otherBranch->id, 'status' => 'active',
        ]);
    }

    /** PO → SO → LO chain for the Bandung branch, delivered (the observer reconciles). */
    private function deliverChain(int $paid, int $redeemed, int $delivered, string $suffix): array
    {
        $po = DeliveryOrder::create([
            'do_number' => "PO2026-{$suffix}",
            'document_type' => 'po',
            'counterparty_type' => 'branch',
            'counterparty_name' => 'SUM Pusat',
            'origin_branch_id' => $this->mainBranch->id,
            'destination_branch_id' => $this->branch->id,
            'cylinder_type' => '3kg',
            'quantity_ordered' => $paid,
            'order_date' => '2026-08-10',
            'requested_by' => $this->branchOwner->id,
            'status' => 'approved',
        ]);

        $so = DeliveryOrder::create([
            'do_number' => "SO2026-{$suffix}",
            'document_type' => 'so',
            'counterparty_type' => 'pertamina',
            'counterparty_name' => 'Pertamina',
            'po_number' => $po->do_number,
            'origin_branch_id' => $this->mainBranch->id,
            'destination_branch_id' => $this->branch->id,
            'cylinder_type' => '3kg',
            'quantity_ordered' => $redeemed,
            'order_date' => '2026-08-10',
            'requested_by' => $this->pusat->id,
            'status' => 'approved',
        ]);

        LpgPrice::create([
            'branch_id' => $this->branch->id,
            'purchase_order_id' => $po->id,
            'cylinder_type' => '3kg',
            'purchase_price' => 10000,
            'selling_price' => 13000,
            'effective_date' => '2026-08-01',
            'created_by' => $this->pusat->id,
        ]);

        $lo = DeliveryOrder::create([
            'do_number' => "LOT2026-{$suffix}",
            'document_type' => 'lo',
            'lo_kind' => 'transportir',
            'po_number' => $po->do_number,
            'so_number' => $so->do_number,
            'origin_branch_id' => $this->mainBranch->id,
            'destination_branch_id' => $this->branch->id,
            'cylinder_type' => '3kg',
            'quantity_ordered' => $delivered,
            'order_date' => '2026-08-11',
            'requested_by' => $this->pusat->id,
            'status' => 'approved',
        ]);

        $lo->update(['status' => 'delivered']);

        return [$po, $so, $lo->fresh()];
    }

    /** Only the T12-08b notification (the existing delivered-LO notification is ignored). */
    private function reconciliationNotifications(): Collection
    {
        return DB::table('notifications')
            ->where('data', 'like', '%Rekonsiliasi Tabung%')
            ->orderBy('id')
            ->get();
    }

    #[Test]
    public function a_new_discrepancy_notifies_hq_and_the_owning_branch_only(): void
    {
        $this->deliverChain(100, 90, 90, '101');

        $rows = $this->reconciliationNotifications();

        // HQ + Bandung owner + Bandung warehouse staff — never another branch, never regional.
        $this->assertEqualsCanonicalizing(
            [$this->pusat->id, $this->branchOwner->id, $this->branchStaff->id],
            $rows->pluck('notifiable_id')->map(fn ($id) => (int) $id)->all()
        );

        $data = json_decode($rows->first()->data, true);

        $this->assertStringContainsString('Case 1', $data['title']);
        $this->assertStringContainsString('PO2026-101', $data['body']);
        $this->assertStringContainsString('SO2026-101', $data['body']);
        $this->assertStringContainsString('LOT2026-101', $data['body']);
        $this->assertStringContainsString('SUM Bandung', $data['body']);
        $this->assertStringContainsString('10 unit unredeemed', $data['body']);
    }

    #[Test]
    public function a_balanced_delivery_notifies_nobody(): void
    {
        $this->deliverChain(100, 100, 100, '102');

        $this->assertCount(0, $this->reconciliationNotifications());
    }

    #[Test]
    public function reprocessing_the_same_loading_order_does_not_notify_twice(): void
    {
        [, , $lo] = $this->deliverChain(100, 100, 105, '103');

        $this->assertGreaterThan(0, $this->reconciliationNotifications()->count());

        DB::table('notifications')->delete();

        app(CylinderReconciliationService::class)->reconcile($lo, $this->pusat->id);

        $this->assertCount(0, $this->reconciliationNotifications());
    }

    #[Test]
    public function a_changed_quantity_notifies_the_recipients_again(): void
    {
        [$po] = $this->deliverChain(100, 90, 90, '104');

        DB::table('notifications')->delete();

        // The PO was corrected after the delivery: the reconciliation now differs.
        $po->update(['quantity_ordered' => 110]);

        app(CylinderReconciliationService::class)->reconcile(
            DeliveryOrder::where('do_number', 'LOT2026-104')->sole(),
            $this->pusat->id
        );

        $rows = $this->reconciliationNotifications();

        $this->assertCount(3, $rows);
        $this->assertStringContainsString('20 unit unredeemed', json_decode($rows->first()->data, true)['body']);
    }
}
