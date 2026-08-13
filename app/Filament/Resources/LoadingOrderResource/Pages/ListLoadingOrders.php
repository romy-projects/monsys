<?php

namespace App\Filament\Resources\LoadingOrderResource\Pages;

use App\Filament\Resources\LoadingOrderResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLoadingOrders extends ListRecords
{
    protected static string $resource = LoadingOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
