<?php

namespace App\Filament\Resources\StockCloseResource\Pages;

use App\Filament\Resources\Pages\EditRecord;
use App\Filament\Resources\StockCloseResource;
use Filament\Actions;

class EditStockClose extends EditRecord
{
    protected static string $resource = StockCloseResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        abort_unless(auth()->user()->canAccessBranch((int) ($data['branch_id'] ?? $this->record->branch_id)), 403);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
