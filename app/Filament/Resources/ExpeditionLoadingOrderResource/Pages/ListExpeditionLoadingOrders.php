<?php

namespace App\Filament\Resources\ExpeditionLoadingOrderResource\Pages;

use App\Filament\Resources\ExpeditionLoadingOrderResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListExpeditionLoadingOrders extends ListRecords
{
    protected static string $resource = ExpeditionLoadingOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
