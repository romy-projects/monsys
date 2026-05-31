<?php

namespace App\Filament\Widgets;

use App\Models\DeliveryOrder;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class PendingDoWidget extends BaseWidget
{
    protected static ?string $heading = '🚛 Pending Delivery Orders / DO Menunggu Persetujuan';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                DeliveryOrder::with(['originBranch', 'destinationBranch', 'expedition'])
                    ->where('status', 'pending_approval')
                    ->latest('order_date')
            )
            ->columns([
                Tables\Columns\TextColumn::make('do_number')
                    ->label('DO Number')
                    ->badge()
                    ->color('warning')
                    ->copyable(),

                Tables\Columns\TextColumn::make('originBranch.name')
                    ->label('From / Asal'),

                Tables\Columns\TextColumn::make('destinationBranch.name')
                    ->label('To / Tujuan'),

                Tables\Columns\TextColumn::make('cylinder_type')
                    ->label('Type')
                    ->badge(),

                Tables\Columns\TextColumn::make('quantity_ordered')
                    ->label('Qty')
                    ->formatStateUsing(fn ($state) => number_format($state) . ' pcs'),

                Tables\Columns\TextColumn::make('order_date')
                    ->label('Order Date')
                    ->date('d M Y'),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'pending_approval',
                        'success' => 'approved',
                        'info'    => 'in_transit',
                        'primary' => 'delivered',
                        'danger'  => 'cancelled',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn () => auth()->user()?->canApproveOrders())
                    ->action(function (DeliveryOrder $record) {
                        $record->update([
                            'status'      => 'approved',
                            'approved_by' => auth()->id(),
                            'approved_at' => now(),
                        ]);
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Approve this DO?')
                    ->modalDescription('This will mark the delivery order as approved and notify the branch.'),

                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn () => auth()->user()?->canApproveOrders())
                    ->action(fn (DeliveryOrder $record) => $record->update(['status' => 'cancelled']))
                    ->requiresConfirmation(),
            ])
            ->striped()
            ->paginated([5, 10]);
    }
}
