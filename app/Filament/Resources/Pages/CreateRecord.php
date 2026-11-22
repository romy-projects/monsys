<?php

namespace App\Filament\Resources\Pages;

use Filament\Resources\Pages\CreateRecord as FilamentCreateRecord;

/**
 * Resource create page that returns to the list screen after "Save" instead of
 * the record's edit screen (Filament's stock behaviour). A view page is still
 * preferred when the resource defines one, so the user never lands in an
 * editable form straight after creating a record.
 *
 * "Create & create another" is untouched: it clears the form and stays on the
 * create page.
 */
abstract class CreateRecord extends FilamentCreateRecord
{
    protected function getRedirectUrl(): string
    {
        $resource = static::getResource();

        if ($resource::hasPage('view') && $resource::canView($this->getRecord())) {
            return $resource::getUrl('view', ['record' => $this->getRecord(), ...$this->getRedirectUrlParameters()]);
        }

        return $resource::getUrl('index');
    }
}
