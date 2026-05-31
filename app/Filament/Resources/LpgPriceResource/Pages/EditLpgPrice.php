<?php

namespace App\Filament\Resources\LpgPriceResource\Pages;

use App\Filament\Resources\LpgPriceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLpgPrice extends EditRecord
{
    protected static string $resource = LpgPriceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
