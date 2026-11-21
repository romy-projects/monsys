<?php

namespace App\Filament\Resources\ReceivableResource\Pages;

use App\Filament\Resources\ReceivableResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditReceivable extends EditRecord
{
    protected static string $resource = ReceivableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('payments')
                ->label('Riwayat Pembayaran')
                ->icon('heroicon-o-list-bullet')
                ->color('gray')
                ->modalContent(fn () => view('filament.finance.payment-history', [
                    'payments' => $this->getRecord()->payments()->with('createdBy')->orderByDesc('paid_on')->orderByDesc('id')->get(),
                ]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Tutup'),

            Actions\DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $this->record->recalculateStatus();
    }
}
