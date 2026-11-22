<?php

namespace App\Filament\Resources\StockMutationResource\Pages;

use App\Filament\Resources\Pages\EditRecord;
use App\Filament\Resources\StockMutationResource;
use Filament\Actions;

class EditStockMutation extends EditRecord
{
    protected static string $resource = StockMutationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->authorize(fn () => StockMutationResource::canDelete($this->getRecord())),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $user = auth()->user();
        $branchIds = array_filter([
            $data['branch_id'] ?? $this->getRecord()->branch_id,
            $data['source_branch_id'] ?? $this->getRecord()->source_branch_id,
            $data['destination_branch_id'] ?? $this->getRecord()->destination_branch_id,
        ]);
        abort_unless(
            count($branchIds) > 0 && collect($branchIds)->every(fn ($branchId) => $user->canAccessBranch((int) $branchId)),
            403
        );

        return $data;
    }
}
