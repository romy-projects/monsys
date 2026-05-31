<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Models\DeliveryOrder;
use Filament\Pages\Page;

class ShipmentTracking extends Page
{
    protected static string $view = 'filament.pages.shipment-tracking';

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'DO & Delivery';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Shipment Tracking / Lacak Kiriman';

    public ?int $branch_id    = null;
    public string $status_filter = 'active';

    public static function getNavigationLabel(): string
    {
        return __('nav.item.do_tracking');
    }

    public function mount(): void
    {
        $user = auth()->user();

        if (! $user?->isOwnerPusat() && ! $user?->isRegionalLeader()) {
            $this->branch_id = $user->branch_id;
        }
    }

    public function getBranches(): \Illuminate\Database\Eloquent\Collection
    {
        $user = auth()->user();

        if ($user?->isOwnerPusat() || $user?->isRegionalLeader()) {
            return Branch::active()->orderBy('name')->get();
        }

        return Branch::where('id', $user?->branch_id)->get();
    }

    public function getShipments(): \Illuminate\Database\Eloquent\Collection
    {
        $user = auth()->user();

        $query = DeliveryOrder::with(['originBranch', 'destinationBranch', 'expedition'])
            ->orderByRaw('(eta IS NULL) ASC, eta ASC');

        if ($this->status_filter === 'active') {
            $query->whereIn('status', ['approved', 'in_transit']);
        } elseif ($this->status_filter !== 'all') {
            $query->where('status', $this->status_filter);
        }

        if ($this->branch_id) {
            $query->where(function ($q) {
                $q->where('destination_branch_id', $this->branch_id)
                    ->orWhere('origin_branch_id', $this->branch_id);
            });
        } elseif (! $user?->isOwnerPusat() && ! $user?->isRegionalLeader()) {
            $branchId = $user->branch_id;
            $query->where(function ($q) use ($branchId) {
                $q->where('destination_branch_id', $branchId)
                    ->orWhere('origin_branch_id', $branchId);
            });
        }

        return $query->get();
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}
