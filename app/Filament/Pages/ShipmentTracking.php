<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Models\DeliveryOrder;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;

class ShipmentTracking extends Page
{
    protected static string $view = 'filament.pages.shipment-tracking';

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'DO & Delivery';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Shipment Tracking / Lacak Kiriman';

    public ?int $branch_id = null;

    public string $status_filter = 'active';

    public static function getNavigationLabel(): string
    {
        return __('nav.item.do_tracking');
    }

    public function mount(): void
    {
        $user = auth()->user();

        if ($user !== null && ! $user->canAccessAllBranches()) {
            $this->branch_id = $user->branch_id && $user->canAccessBranch((int) $user->branch_id)
                ? (int) $user->branch_id
                : (count($user->accessibleBranchIds()) === 1 ? $user->accessibleBranchIds()[0] : null);
        }
    }

    public function getBranches(): Collection
    {
        $user = auth()->user();

        $query = Branch::active()->orderBy('name');
        $user?->applyAccessibleBranchScope($query, 'branches.id');

        return $query->get();
    }

    public function getShipments(): Collection
    {
        $user = auth()->user();

        // Shipments are PO (HQ → branch) and LO (transportir/expedition legs).
        // document_type 'do' is legacy history only and must stay out of live tracking (N-GEN-7).
        $query = DeliveryOrder::accessibleTo($user)
            ->with(['originBranch', 'destinationBranch', 'expedition'])
            ->whereIn('document_type', ['po', 'lo'])
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
        }

        return $query->get();
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->hasPermission('reports.shipment_tracking.view') ?? false;
    }
}
