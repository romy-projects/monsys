<?php

namespace App\Filament\Widgets;

use App\Models\Branch;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class StockAlertWidget extends BaseWidget
{
    protected static ?string $heading = '⚠️ Low Stock Alerts / Peringatan Stok Menipis';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Branch::active()
                    ->withSum('stockItems as total_full', 'qty_full')
                    ->withSum('stockItems as total_empty', 'qty_empty')
                    ->having('total_full', '<', 50)
                    ->orderBy('total_full')
            )
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Code')
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Branch / Cabang')
                    ->weight(\Filament\Support\Enums\FontWeight::SemiBold),

                Tables\Columns\TextColumn::make('city')
                    ->label('City'),

                Tables\Columns\TextColumn::make('total_full')
                    ->label('Full Cylinders')
                    ->formatStateUsing(fn ($state) => number_format((int)$state) . ' pcs')
                    ->color(fn ($state) => $state < 20 ? 'danger' : 'warning'),

                Tables\Columns\TextColumn::make('total_empty')
                    ->label('Empty Cylinders')
                    ->formatStateUsing(fn ($state) => number_format((int)$state) . ' pcs')
                    ->color('secondary'),
            ])
            ->striped()
            ->paginated(false);
    }
}
