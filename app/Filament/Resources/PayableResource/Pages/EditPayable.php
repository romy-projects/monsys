<?php

namespace App\Filament\Resources\PayableResource\Pages;

use App\Filament\Resources\PayableResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPayable extends EditRecord
{
    protected static string $resource = PayableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('payments')
                ->label('Riwayat Pembayaran')
                ->icon('heroicon-o-list-bullet')
                ->color('gray')
                ->modalContent(fn() => view('filament.finance.payment-history', [
                    'payments' => $this->getRecord()->payments()->with('createdBy')->orderByDesc('paid_on')->orderByDesc('id')->get(),
                ]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Tutup'),

            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ((float) $data['amount'] < (float) $this->getRecord()->paid_amount) {
            Notification::make()
                ->title('Nominal tidak valid')
                ->body('Nominal tagihan tidak boleh lebih kecil dari total pembayaran yang sudah dicatat.')
                ->danger()
                ->send();

            $this->halt();
        }

        return $data;
    }

    protected function afterSave(): void
    {
        // BALI-17: status stays derived from amount vs paid_amount.
        $this->getRecord()->recalculateStatus();
    }
}
