<?php

namespace App\Filament\Resources\Pages;

use Filament\Resources\Pages\EditRecord as FilamentEditRecord;

/**
 * Resource edit page that returns to the list screen after "Save changes".
 * Filament's stock edit page returns null from getRedirectUrl(), so saving
 * leaves the operator on the form. The "Saved" notification is still sent
 * before the redirect, so the confirmation remains visible on the list.
 */
abstract class EditRecord extends FilamentEditRecord
{
    protected function getRedirectUrl(): ?string
    {
        return static::getResource()::getUrl('index');
    }
}
