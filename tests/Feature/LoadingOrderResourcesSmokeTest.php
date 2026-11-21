<?php

namespace Tests\Feature;

use App\Filament\Resources\ExpeditionLoadingOrderResource\Pages\CreateExpeditionLoadingOrder;
use App\Filament\Resources\ExpeditionLoadingOrderResource\Pages\ListExpeditionLoadingOrders;
use App\Filament\Resources\LoadingOrderResource\Pages\CreateLoadingOrder;
use App\Filament\Resources\LoadingOrderResource\Pages\ListLoadingOrders;
use App\Filament\Resources\PurchaseOrderResource\Pages\CreatePurchaseOrder;
use App\Filament\Resources\TransportirRateResource\Pages\CreateTransportirRate;
use App\Filament\Resources\TransportirRateResource\Pages\ListTransportirRates;
use App\Models\Branch;
use App\Models\Transportir;
use App\Models\TransportirRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Smoke tests for the Phase 4 Filament screens: every page must build its form
 * and table without errors (a broken schema only surfaces when rendered).
 */
class LoadingOrderResourcesSmokeTest extends TestCase
{
    use RefreshDatabase;

    private User $pusat;

    protected function setUp(): void
    {
        parent::setUp();

        $mainBranch = Branch::create([
            'code' => 'PST',
            'name' => 'SUM Pusat',
            'city' => 'Jakarta',
            'status' => 'active',
        ]);

        $this->pusat = User::create([
            'name' => 'Pusat User',
            'email' => 'pusat@test.com',
            'password' => bcrypt('password'),
            'role' => 'owner_pusat',
            'branch_id' => $mainBranch->id,
            'status' => 'active',
        ]);

        $this->actingAs($this->pusat);
    }

    #[Test]
    public function transportir_loading_order_pages_render(): void
    {
        Livewire::test(ListLoadingOrders::class)->assertOk();

        Livewire::test(CreateLoadingOrder::class)
            ->assertOk()
            ->assertFormFieldExists('po_number')
            ->assertFormFieldExists('destination_branch_id')
            ->assertFormFieldExists('transportir_id')
            ->assertFormFieldDoesNotExist('expedition_cost');
    }

    #[Test]
    public function expedition_loading_order_pages_render(): void
    {
        Livewire::test(ListExpeditionLoadingOrders::class)->assertOk();

        Livewire::test(CreateExpeditionLoadingOrder::class)
            ->assertOk()
            ->assertFormFieldExists('transportir_lo_number')
            ->assertFormFieldExists('container_number')
            ->assertFormFieldExists('destination_branch_id')
            ->assertFormFieldExists('expedition_cost');
    }

    #[Test]
    public function purchase_order_form_renders_the_transportir_rate_section(): void
    {
        $transportir = Transportir::create([
            'name' => 'PT Raja Sumber Cahaya',
            'code' => 'TRP-01',
            'status' => 'active',
        ]);

        // BALI-10: the read-only rate section only appears once a transportir is chosen.
        Livewire::test(CreatePurchaseOrder::class)
            ->assertOk()
            ->fillForm([
                'transportir_id' => $transportir->id,
                'cylinder_type' => '12kg',
                'quantity_ordered' => 10,
            ])
            ->assertSee('Transporter Rate & Cost')
            ->assertSee('Rate per Cylinder')
            ->assertSee('Transportir Cost (rate × qty)');
    }

    #[Test]
    public function transportir_rate_pages_render(): void
    {
        Livewire::test(ListTransportirRates::class)->assertOk();

        Livewire::test(CreateTransportirRate::class)
            ->assertOk()
            ->assertFormFieldExists('transportir_id')
            ->assertFormFieldExists('cylinder_type')
            ->assertFormFieldExists('rate')
            ->assertFormFieldExists('effective_date');
    }

    #[Test]
    public function a_rate_can_be_created_once_per_transportir_type_and_date(): void
    {
        $transportir = Transportir::create([
            'name' => 'PT Mahardika',
            'code' => 'TRP-02',
            'status' => 'active',
        ]);

        $payload = [
            'transportir_id' => $transportir->id,
            'cylinder_type' => '12kg',
            'rate' => 5000,
            'effective_date' => today()->toDateString(),
        ];

        Livewire::test(CreateTransportirRate::class)
            ->fillForm($payload)
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('transportir_rates', [
            'transportir_id' => $transportir->id,
            'cylinder_type' => '12kg',
        ]);

        Livewire::test(CreateTransportirRate::class)
            ->fillForm($payload)
            ->call('create')
            ->assertHasFormErrors(['effective_date']);

        $this->assertSame(1, TransportirRate::count());
    }
}
