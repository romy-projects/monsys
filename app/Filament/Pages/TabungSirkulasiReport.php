<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Models\CylinderCirculation;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

class TabungSirkulasiReport extends Page
{
    protected static string $view = 'filament.pages.tabung-sirkulasi-report';

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Tabung Sirkulasi';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Laporan Sirkulasi Tabung';

    public ?int $branch_id = null;

    public ?string $cylinder_type = null;

    public ?string $date_from = null;

    public ?string $date_until = null;

    public static function getNavigationLabel(): string
    {
        return 'Laporan Sirkulasi';
    }

    public function mount(): void
    {
        $user = auth()->user();

        if (! $user?->isOwnerPusat() && ! $user?->isRegionalLeader()) {
            $this->branch_id = $user->branch_id;
        }
    }

    public function getBranches(): Collection
    {
        $user = auth()->user();

        if ($user?->isOwnerPusat() || $user?->isRegionalLeader()) {
            return Branch::where('status', 'active')->orderBy('name')->get();
        }

        return Branch::where('id', $user?->branch_id)->get();
    }

    /**
     * Get ledger entries with running balance per cylinder type.
     */
    public function getLedger(): array
    {
        $types = ['3kg', '5.5kg', '12kg', '50kg'];
        $selectedType = $this->cylinder_type;

        $query = CylinderCirculation::query()
            ->with('branch')
            ->orderBy('transaction_date')
            ->orderBy('created_at');

        // Branch filter
        if ($this->branch_id) {
            $query->where('branch_id', $this->branch_id);
        } elseif (! auth()->user()?->isOwnerPusat() && ! auth()->user()?->isRegionalLeader()) {
            $query->where('branch_id', auth()->user()?->branch_id);
        }

        // Cylinder type filter
        if ($selectedType && in_array($selectedType, $types)) {
            $query->where('cylinder_type', $selectedType);
        }

        // Date range
        if ($this->date_from) {
            $query->whereDate('transaction_date', '>=', $this->date_from);
        }
        if ($this->date_until) {
            $query->whereDate('transaction_date', '<=', $this->date_until);
        }

        $records = $query->get();

        // Group by cylinder type for running balance
        $grouped = $records->groupBy('cylinder_type');

        $result = [];
        $runningBalances = array_fill_keys($types, 0);

        foreach ($types as $type) {
            if ($selectedType && $selectedType !== $type) {
                continue;
            }

            $typeRecords = $grouped->get($type, collect());
            $balance = 0;
            $entries = [];

            foreach ($typeRecords as $record) {
                $qty = (int) $record->quantity;
                if ($record->direction === 'debit') {
                    $balance += $qty;
                } else {
                    $balance -= $qty;
                }

                $entries[] = [
                    'id'                => $record->id,
                    'transaction_date'  => $record->transaction_date,
                    'so_number'         => $record->so_number,
                    'description'       => $record->description,
                    'transaction_type'  => $record->transaction_type,
                    'direction'         => $record->direction,
                    'quantity'          => $qty,
                    'balance'           => max(0, $balance),
                    'branch_name'       => $record->branch?->name,
                    'handled_by'        => $record->handled_by,
                    'container_no'      => $record->container_no,
                ];
            }

            $runningBalances[$type] = max(0, $balance);

            $result[$type] = [
                'entries' => $entries,
                'balance' => max(0, $balance),
            ];
        }

        return [
            'types'     => $selectedType ? [$selectedType] : $types,
            'result'    => $result,
            'totals'    => $runningBalances,
            'count'     => $records->count(),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}