<?php

namespace App\Filament\Widgets;

use App\Models\Branch;
use App\Models\CylinderCirculation;
use Filament\Widgets\Widget;

class CylinderCirculationSummaryWidget extends Widget
{
    protected static string $view = 'filament.widgets.cylinder-circulation-summary';

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();
        return $user && ($user->isOwnerPusat() || $user->isRegionalLeader());
    }

    public function getMatrix(): array
    {
        $types = ['3kg', '5.5kg', '12kg', '50kg'];
        $branches = Branch::where('status', 'active')->orderBy('name')->get();

        $rows = [];
        $totals = array_fill_keys($types, 0);

        foreach ($branches as $branch) {
            $row = ['branch' => $branch];

            foreach ($types as $type) {
                // Calculate running balance from all transactions for this branch + type
                $debits = (int) CylinderCirculation::where('branch_id', $branch->id)
                    ->where('cylinder_type', $type)
                    ->where('direction', 'debit')
                    ->sum('quantity');

                $credits = (int) CylinderCirculation::where('branch_id', $branch->id)
                    ->where('cylinder_type', $type)
                    ->where('direction', 'kredit')
                    ->sum('quantity');

                $balance = max(0, $debits - $credits);
                $row[$type] = $balance;
                $totals[$type] += $balance;
            }

            $rows[] = $row;
        }

        return [
            'rows'   => $rows,
            'types'  => $types,
            'totals' => $totals,
        ];
    }
}
