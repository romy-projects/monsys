<?php

namespace App\Filament\Widgets;

use App\Models\DailySale;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class SalesChartWidget extends ChartWidget
{
    protected static ?string $heading = '📈 Sales Performance — Last 14 Days';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $maxHeight = '300px';

    public ?string $filter = 'week';

    protected function getFilters(): ?array
    {
        return [
            'week'  => 'Last 7 Days / 7 Hari Terakhir',
            'month' => 'Last 30 Days / 30 Hari Terakhir',
        ];
    }

    protected function getData(): array
    {
        $days  = $this->filter === 'month' ? 30 : 7;
        $user  = auth()->user();

        $sales = DailySale::query()
            ->selectRaw('sale_date, SUM(total_revenue) as total')
            ->whereBetween('sale_date', [now()->subDays($days), now()])
            ->when(! $user?->isOwnerPusat() && ! $user?->isRegionalLeader(), fn ($q) =>
                $q->where('branch_id', $user?->branch_id)
            )
            ->groupBy('sale_date')
            ->orderBy('sale_date')
            ->pluck('total', 'sale_date');

        $labels = collect();
        $data   = collect();

        for ($i = $days; $i >= 0; $i--) {
            $date     = Carbon::today()->subDays($i)->format('Y-m-d');
            $label    = Carbon::today()->subDays($i)->format('d M');
            $labels[] = $label;
            $data[]   = $sales->get($date, 0);
        }

        return [
            'datasets' => [
                [
                    'label'           => 'Revenue (Rp)',
                    'data'            => $data->toArray(),
                    'backgroundColor' => 'rgba(30, 58, 95, 0.15)',
                    'borderColor'     => '#2d6a9f',
                    'borderWidth'     => 2,
                    'fill'            => true,
                    'tension'         => 0.4,
                ],
            ],
            'labels' => $labels->toArray(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
