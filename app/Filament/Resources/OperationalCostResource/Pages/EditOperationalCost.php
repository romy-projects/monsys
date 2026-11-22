<?php

namespace App\Filament\Resources\OperationalCostResource\Pages;

use App\Filament\Resources\OperationalCostResource;
use App\Filament\Resources\Pages\EditRecord;
use Filament\Actions;

class EditOperationalCost extends EditRecord
{
    protected static string $resource = OperationalCostResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        abort_unless(
            auth()->user()->canAccessBranch((int) ($data['branch_id'] ?? $this->getRecord()->branch_id)),
            403
        );

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->authorize(fn () => OperationalCostResource::can('delete', $this->getRecord())),
        ];
    }
}
