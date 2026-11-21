<?php

namespace App\Filament\Resources\CylinderCirculationResource\Pages;

use App\Filament\Resources\CylinderCirculationResource;
use App\Models\CylinderCirculation;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListCylinderCirculations extends ListRecords
{
    protected static string $resource = CylinderCirculationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    protected function getTableQuery(): Builder
    {
        $query = CylinderCirculation::query()->with('branch');
        auth()->user()?->applyAccessibleBranchScope($query);

        return $query;
    }
}
