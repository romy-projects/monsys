<?php

namespace App\Filament\Resources\CylinderCirculationResource\Pages;

use App\Filament\Resources\CylinderCirculationResource;
use App\Filament\Resources\Pages\EditRecord;
use App\Models\CylinderCirculation;
use Filament\Actions;

class EditCylinderCirculation extends EditRecord
{
    protected static string $resource = CylinderCirculationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->authorize(fn () => CylinderCirculationResource::canDelete($this->getRecord())),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var CylinderCirculation $record */
        $record = $this->record;

        abort_unless(CylinderCirculationResource::canEdit($record), 403);

        $data['branch_id'] = $record->branch_id;

        return $data;
    }
}
