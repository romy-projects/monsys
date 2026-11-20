<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LpgPrice;
use App\Models\Transportir;
use App\Models\TransportirRate;
use App\Models\User;
use App\Support\BranchPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BALI-10 / BALI-14: effective-dated transportir rates and the HPP / selling
 * price breakdown after transportir and expedition costs.
 */
class TransportirRateTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $pusat;
    private Transportir $rajaSumber;
    private Transportir $mahardika;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'code'   => 'PST',
            'name'   => 'SUM Pusat',
            'city'   => 'Jakarta',
            'status' => 'active',
        ]);

        $this->pusat = User::create([
            'name'      => 'Pusat User',
            'email'     => 'pusat@test.com',
            'password'  => bcrypt('password'),
            'role'      => 'owner_pusat',
            'branch_id' => $this->branch->id,
            'status'    => 'active',
        ]);

        $this->rajaSumber = Transportir::create([
            'name'   => 'PT Raja Sumber Cahaya',
            'code'   => 'TRP-01',
            'status' => 'active',
        ]);

        $this->mahardika = Transportir::create([
            'name'   => 'PT Mahardika',
            'code'   => 'TRP-02',
            'status' => 'active',
        ]);
    }

    private function rate(Transportir $transportir, string $type, float $amount, string $effectiveDate): TransportirRate
    {
        return TransportirRate::create([
            'transportir_id' => $transportir->id,
            'cylinder_type'  => $type,
            'rate'           => $amount,
            'effective_date' => $effectiveDate,
            'created_by'     => $this->pusat->id,
        ]);
    }

    #[Test]
    public function rate_at_date_uses_the_newest_effective_row(): void
    {
        $this->rate($this->rajaSumber, '12kg', 5000, '2026-01-01');
        $this->rate($this->rajaSumber, '12kg', 6000, '2026-06-01');

        $this->assertSame(5000.0, (float) TransportirRate::rateAtDate($this->rajaSumber->id, '12kg', '2026-03-15')?->rate);
        $this->assertSame(6000.0, (float) TransportirRate::rateAtDate($this->rajaSumber->id, '12kg', '2026-07-01')?->rate);
        $this->assertNull(TransportirRate::rateAtDate($this->rajaSumber->id, '12kg', '2025-12-31'));
    }

    #[Test]
    public function rates_are_per_transportir_and_cylinder_type(): void
    {
        // Amounts quoted in the Bali meeting note (BALI-10).
        $note = [
            [$this->rajaSumber, '5.5kg', 3500],
            [$this->rajaSumber, '12kg', 5000],
            [$this->rajaSumber, '50kg', 10000],
            [$this->mahardika, '5.5kg', 4000],
            [$this->mahardika, '12kg', 5000],
            [$this->mahardika, '50kg', 10000],
        ];

        foreach ($note as [$transportir, $type, $amount]) {
            $this->rate($transportir, $type, $amount, today()->toDateString());
        }

        $this->assertSame(3500.0, TransportirRate::amountAtDate($this->rajaSumber->id, '5.5kg'));
        $this->assertSame(4000.0, TransportirRate::amountAtDate($this->mahardika->id, '5.5kg'));
        $this->assertSame(0.0, TransportirRate::amountAtDate($this->rajaSumber->id, '3kg'));

        $this->assertSame(
            ['5.5kg' => 4000.0, '12kg' => 5000.0, '50kg' => 10000.0],
            TransportirRate::ratesFor($this->mahardika->id)->all()
        );
    }

    #[Test]
    public function breakdown_applies_rate_and_flat_delivery_fee(): void
    {
        LpgPrice::create([
            'branch_id'      => $this->branch->id,
            'cylinder_type'  => '12kg',
            'purchase_price' => 20000,
            'selling_price'  => 27000,
            'effective_date' => today()->toDateString(),
            'created_by'     => $this->pusat->id,
        ]);
        $this->rate($this->rajaSumber, '12kg', 5000, today()->toDateString());

        $result = BranchPricing::breakdown([
            'branch_id'       => $this->branch->id,
            'cylinder_type'   => '12kg',
            'quantity'        => 100,
            'transportir_id'  => $this->rajaSumber->id,
            'expedition_cost' => 15000,
        ]);

        // HPP per cylinder = purchase price + transportir rate.
        $this->assertSame(25000.0, $result['hpp_per_cylinder']);
        $this->assertSame(2500000.0, $result['hpp_total']);

        // Transportir cost scales with quantity, the delivery fee does not.
        $this->assertSame(500000.0, $result['transportir_total']);
        $this->assertSame(15000.0, $result['delivery_fee']);

        $this->assertSame(2700000.0, $result['gross_total']);
        $this->assertFalse($result['fee_charged_to_buyer']);
        $this->assertSame(2700000.0, $result['branch_total']);
        $this->assertSame(185000.0, $result['net_after_costs']);
    }

    #[Test]
    public function breakdown_reproduces_the_meeting_note_simulation(): void
    {
        // The note's simulation: 2.216.000 − 15.000 − 5.000 = 2.196.000.
        LpgPrice::create([
            'branch_id'      => $this->branch->id,
            'cylinder_type'  => '50kg',
            'purchase_price' => 0,
            'selling_price'  => 2216000,
            'effective_date' => today()->toDateString(),
            'created_by'     => $this->pusat->id,
        ]);
        $this->rate($this->rajaSumber, '50kg', 15000, today()->toDateString());

        $result = BranchPricing::breakdown([
            'branch_id'           => $this->branch->id,
            'cylinder_type'       => '50kg',
            'quantity'            => 1,
            'transportir_id'      => $this->rajaSumber->id,
            'expedition_cost'     => 5000,
            'charge_fee_to_buyer' => false,
        ]);

        // The note's simulation has the delivery fee absorbed by HQ, not billed
        // to the branch: 2.216.000 − 15.000 − 5.000 = 2.196.000.
        $this->assertSame(2216000.0, $result['gross_total']);
        $this->assertSame(2216000.0, $result['branch_total']);
        $this->assertSame(2196000.0, $result['net_after_costs']);
    }

    #[Test]
    public function breakdown_never_fails_when_no_rate_or_price_is_configured(): void
    {
        $result = BranchPricing::breakdown([
            'branch_id'     => $this->branch->id,
            'cylinder_type' => '3kg',
            'quantity'      => 10,
        ]);

        $this->assertFalse($result['price_found']);
        $this->assertSame(0.0, $result['transportir_rate']);
        $this->assertSame(0.0, $result['gross_total']);
        $this->assertNull($result['lpg_price_id']);
    }
}
