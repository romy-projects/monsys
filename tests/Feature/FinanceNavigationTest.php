<?php

namespace Tests\Feature;

use App\Filament\Pages\HppReport;
use App\Filament\Pages\ReceivablesAging;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\OperationalCostResource;
use App\Filament\Resources\PayableResource;
use App\Filament\Resources\ReceivableResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_navigation_is_registered_for_roles_with_read_permissions(): void
    {
        $financeItems = [
            HppReport::class,
            ReceivablesAging::class,
            InvoiceResource::class,
            OperationalCostResource::class,
            PayableResource::class,
            ReceivableResource::class,
        ];

        $ownerPusat = User::create([
            'name' => 'Owner Pusat',
            'email' => 'finance-owner@example.test',
            'password' => bcrypt('password'),
            'role' => 'owner_pusat',
            'status' => 'active',
        ]);
        $this->actingAs($ownerPusat);

        foreach ($financeItems as $item) {
            $this->assertTrue($item::shouldRegisterNavigation());
        }

        $regionalLeader = User::create([
            'name' => 'Regional Leader',
            'email' => 'finance-regional@example.test',
            'password' => bcrypt('password'),
            'role' => 'regional_leader',
            'status' => 'active',
        ]);
        $this->actingAs($regionalLeader);

        foreach ($financeItems as $item) {
            $this->assertTrue($item::shouldRegisterNavigation());
        }
    }
}
