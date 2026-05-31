<?php

namespace App\Filament\Resources\DeliveryOrderResource\Pages;

use App\Filament\Resources\DeliveryOrderResource;
use App\Models\StockClose;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateDeliveryOrder extends CreateRecord
{
    protected static string $resource = DeliveryOrderResource::class;

    protected function beforeCreate(): void
    {
        $user = auth()->user();

        if ($user->isOwnerPusat() || $user->isRegionalLeader()) {
            return;
        }

        if (! StockClose::isTodaySubmitted($user->branch_id)) {
            Notification::make()
                ->title('Stock Close Required')
                ->body("Today's stock close must be submitted before creating a Delivery Order.")
                ->danger()
                ->persistent()
                ->send();

            $this->halt();
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['requested_by'] = auth()->id();
        $data['status']       = 'draft';

        return $data;
    }
}
