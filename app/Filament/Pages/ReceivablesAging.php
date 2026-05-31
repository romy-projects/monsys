<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Models\Receivable;
use Filament\Pages\Page;

class ReceivablesAging extends Page
{
    protected static string $view = 'filament.pages.receivables-aging';

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Receivables Aging';

    public ?int $branch_id = null;

    public static function getNavigationLabel(): string
    {
        return __('nav.item.receivables_aging');
    }

    public function mount(): void
    {
        $user = auth()->user();

        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            $this->branch_id = $user->branch_id;
        }
    }

    public function getBranches(): \Illuminate\Support\Collection
    {
        return Branch::active()->orderBy('name')->pluck('name', 'id');
    }

    public function getAgingData(): array
    {
        $user  = auth()->user();
        $today = today();

        $query = Receivable::query()
            ->whereNotIn('status', ['paid'])
            ->with('branch');

        if ($this->branch_id) {
            $query->where('branch_id', $this->branch_id);
        } elseif (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            $query->where('branch_id', $user->branch_id);
        }

        $buckets = [
            'current' => ['label' => 'Current (not yet due)',  'color' => 'green',  'items' => collect()],
            '1_30'    => ['label' => '1–30 days overdue',      'color' => 'yellow', 'items' => collect()],
            '31_60'   => ['label' => '31–60 days overdue',     'color' => 'orange', 'items' => collect()],
            '61_90'   => ['label' => '61–90 days overdue',     'color' => 'red',    'items' => collect()],
            '90plus'  => ['label' => '> 90 days overdue',      'color' => 'red',    'items' => collect()],
        ];

        $grandTotal = 0.0;

        $query->get()->each(function (Receivable $r) use (&$buckets, &$grandTotal, $today) {
            $balance = max(0.0, (float) $r->amount - (float) $r->paid_amount);

            if ($balance <= 0) {
                return;
            }

            $grandTotal += $balance;

            if ($r->due_date->gte($today)) {
                $key = 'current';
            } else {
                $daysLate = $today->diffInDays($r->due_date);

                $key = match (true) {
                    $daysLate <= 30 => '1_30',
                    $daysLate <= 60 => '31_60',
                    $daysLate <= 90 => '61_90',
                    default         => '90plus',
                };
            }

            $buckets[$key]['items']->push([
                'id'             => $r->id,
                'branch'         => $r->branch,
                'buyer_name'     => $r->buyer_name,
                'buyer_type'     => $r->buyer_type,
                'invoice_number' => $r->invoice_number,
                'invoice_date'   => $r->invoice_date,
                'due_date'       => $r->due_date,
                'amount'         => (float) $r->amount,
                'paid_amount'    => (float) $r->paid_amount,
                'balance'        => $balance,
                'days_overdue'   => $r->due_date->lt($today) ? $today->diffInDays($r->due_date) : 0,
            ]);
        });

        return [
            'buckets'     => $buckets,
            'grand_total' => $grandTotal,
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->canViewFinance() ?? false;
    }
}
