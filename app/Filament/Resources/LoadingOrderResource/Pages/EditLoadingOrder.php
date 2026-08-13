<?php

namespace App\Filament\Resources\LoadingOrderResource\Pages;

use App\Filament\Resources\LoadingOrderResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLoadingOrder extends EditRecord
{
    protected static string $resource = LoadingOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
