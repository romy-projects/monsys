<?php

namespace App\Providers;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CylinderCirculation;
use App\Models\CylinderReconciliation;
use App\Models\DailySale;
use App\Models\DeliveryOrder;
use App\Models\Expedition;
use App\Models\Invoice;
use App\Models\LpgPrice;
use App\Models\OperationalCost;
use App\Models\Payable;
use App\Models\Receivable;
use App\Models\SalesTarget;
use App\Models\StockClose;
use App\Models\StockItem;
use App\Models\StockMutation;
use App\Models\Transportir;
use App\Models\TransportirRate;
use App\Models\User;
use App\Models\Vehicle;
use App\Observers\DeliveryOrderObserver;
use App\Observers\LpgPriceObserver;
use App\Observers\StockItemObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->configureTransportSecurity();

        // Dotted abilities in the permission catalog are resolved dynamically.
        Gate::before(function (?User $user, string $ability, array $arguments = []): ?bool {
            if ($user === null) {
                return null;
            }

            if (str_contains($ability, '.')) {
                return $user->hasPermission($ability);
            }

            $modules = [
                Branch::class => 'branches',
                Customer::class => 'customers',
                CylinderCirculation::class => 'cylinder_circulations',
                CylinderReconciliation::class => 'reconciliations',
                DailySale::class => 'sales',
                Expedition::class => 'expeditions',
                Invoice::class => 'invoices',
                LpgPrice::class => 'prices',
                OperationalCost::class => 'operational_costs',
                Payable::class => 'payables',
                Receivable::class => 'receivables',
                SalesTarget::class => 'sales_targets',
                StockClose::class => 'stock_closes',
                StockItem::class => 'stock',
                StockMutation::class => 'stock_mutations',
                Transportir::class => 'transportirs',
                TransportirRate::class => 'transportir_rates',
                User::class => 'users',
                Vehicle::class => 'vehicles',
            ];
            $actions = [
                'viewAny' => 'view',
                'view' => 'view',
                'create' => 'create',
                'update' => 'update',
                'delete' => 'delete',
                'deleteAny' => 'delete',
                'forceDelete' => 'delete',
                'forceDeleteAny' => 'delete',
                'restore' => 'update',
                'restoreAny' => 'update',
                'replicate' => 'create',
            ];
            $model = $arguments[0] ?? null;
            $modelClass = is_string($model)
                ? $model
                : ($model instanceof Model ? $model::class : null);
            $module = $modelClass === null ? null : ($modules[$modelClass] ?? null);
            if ($model instanceof DeliveryOrder) {
                $module = match ($model->document_type) {
                    'po' => 'purchase_orders',
                    'so' => 'sales_orders',
                    'lo' => 'loading_orders',
                    'do' => 'legacy_delivery_orders',
                    default => null,
                };
            }
            $action = $actions[$ability] ?? null;

            if ($module === null || $action === null) {
                return null;
            }

            if ($model instanceof Model && ! $user->canAccessModelRecord($model, $action)) {
                return false;
            }

            return $user->hasPermission("{$module}.{$action}");
        });

        StockItem::observe(StockItemObserver::class);
        DeliveryOrder::observe(DeliveryOrderObserver::class);
        LpgPrice::observe(LpgPriceObserver::class);

        // Morph map for Invoice polymorphic reference
        Relation::morphMap([
            'branch' => Branch::class,
            'customer' => Customer::class,
        ]);

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(
                $request->user()?->id ?: $request->ip()
            );
        });
    }

    /**
     * Phase 16A / T16-02 — trusted proxies and HTTPS enforcement.
     *
     * TrustProxies reads its allow-list statically, and bootstrap/app.php's
     * middleware callback runs before the config repository is loaded, so the
     * value is applied here instead of in bootstrap/app.php.
     */
    private function configureTransportSecurity(): void
    {
        TrustProxies::at((array) config('security.trusted_proxies', []));

        if (config('security.force_https')) {
            URL::forceScheme('https');
        }
    }
}
