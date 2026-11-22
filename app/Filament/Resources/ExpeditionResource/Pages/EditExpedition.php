<?php

namespace App\Filament\Resources\ExpeditionResource\Pages;

use App\Filament\Resources\ExpeditionResource;
use App\Filament\Resources\Pages\EditRecord;
use Filament\Actions;

class EditExpedition extends EditRecord
{
    protected static string $resource = ExpeditionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
