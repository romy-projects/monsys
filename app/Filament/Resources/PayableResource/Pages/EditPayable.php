<?php

namespace App\Filament\Resources\PayableResource\Pages;

use App\Filament\Resources\PayableResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPayable extends EditRecord
{
    protected static string $resource = PayableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
