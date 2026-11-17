<?php

namespace Tests\Feature;

use App\Filament\Pages\TabungSirkulasiReport;
use App\Models\Branch;
use App\Models\CylinderCirculation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TabungSirkulasiReportTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $hqUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'code' => 'BDG',
            'name' => 'SUM Bandung',
            'city' => 'Bandung',
            'status' => 'active',
        ]);
        $this->hqUser = User::create([
            'name' => 'HQ User',
            'email' => 'hq-circulation-report@test.com',
            'password' => bcrypt('password'),
            'role' => 'owner_pusat',
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);
    }

    #[Test]
    public function report_renders_empty_entries_with_zero_aggregates(): void
    {
        $this->actingAs($this->hqUser);

        $component = Livewire::test(TabungSirkulasiReport::class)
            ->assertSee('No records found for 3kg.');

        $report = $component->instance()->getLedger();

        $this->assertSame([], $report['result']['3kg']['entries']);
        $this->assertSame(0, $report['result']['3kg']['debit_total']);
        $this->assertSame(0, $report['result']['3kg']['credit_total']);
        $this->assertSame(0, $report['result']['3kg']['balance']);
    }

    #[Test]
    public function report_renders_and_aggregates_debits_and_credits_for_entries(): void
    {
        CylinderCirculation::create([
            'branch_id' => $this->branch->id,
            'transaction_date' => '2026-11-15',
            'transaction_type' => 'kirim',
            'cylinder_type' => '3kg',
            'direction' => 'debit',
            'quantity' => 5,
        ]);
        CylinderCirculation::create([
            'branch_id' => $this->branch->id,
            'transaction_date' => '2026-11-16',
            'transaction_type' => 'kirim',
            'cylinder_type' => '3kg',
            'direction' => 'debit',
            'quantity' => 3,
        ]);
        CylinderCirculation::create([
            'branch_id' => $this->branch->id,
            'transaction_date' => '2026-11-17',
            'transaction_type' => 'bongkar_kosong',
            'cylinder_type' => '3kg',
            'direction' => 'kredit',
            'quantity' => 2,
        ]);
        $this->actingAs($this->hqUser);

        $component = Livewire::test(TabungSirkulasiReport::class)
            ->assertSee('Saldo Akhir')
            ->assertSee('No records found for 5.5kg.');

        $typeData = $component->instance()->getLedger()['result']['3kg'];

        $this->assertCount(3, $typeData['entries']);
        $this->assertSame(8, $typeData['debit_total']);
        $this->assertSame(2, $typeData['credit_total']);
        $this->assertSame(6, $typeData['balance']);
    }
}
