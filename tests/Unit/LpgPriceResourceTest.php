<?php

namespace Tests\Unit;

use App\Filament\Resources\LpgPriceResource;
use App\Models\LpgPrice;
use Tests\TestCase;

class LpgPriceResourceTest extends TestCase
{
    public function test_lpg_prices_cannot_be_edited(): void
    {
        $this->assertFalse(LpgPriceResource::canEdit(new LpgPrice()));
    }
}
