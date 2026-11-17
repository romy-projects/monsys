<?php

namespace Tests\Feature;

use App\Filament\Pages\HppReport;
use App\Filament\Pages\ReceivablesAging;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\OperationalCostResource;
use App\Filament\Resources\PayableResource;
use App\Filament\Resources\ReceivableResource;
use App\Models\User;
use Tests\TestCase;

class FinanceNavigationTest extends TestCase
{
    public function test_finance_navigation_is_only_registered_for_hq_users(): void
    {
        $financeItems = [
            HppReport::class,
            ReceivablesAging::class,
            InvoiceResource::class,
            OperationalCostResource::class,
            PayableResource::class,
            ReceivableResource::class,
        ];

        $this->actingAs(new User(['role' => 'owner_pusat']));

        foreach ($financeItems as $item) {
            $this->assertTrue($item::shouldRegisterNavigation());
        }

        $this->actingAs(new User(['role' => 'regional_leader']));

        foreach ($financeItems as $item) {
            $this->assertFalse($item::shouldRegisterNavigation());
        }
    }
}
