<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchPricingController;
use App\Http\Controllers\Api\CostController;
use App\Http\Controllers\Api\CylinderCirculationController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DeliveryOrderController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\LoadingOrderController;
use App\Http\Controllers\Api\MasterDataController;
use App\Http\Controllers\Api\PayableController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\ReconciliationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SalesController;
use App\Http\Controllers\Api\SalesOrderController;
use App\Http\Controllers\Api\ScanController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\ImportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::group([], function () {

    // Bare prefix probe — `GET /api/v1` used to answer with the framework's 404
    // page, which made a correctly mounted API look missing. It now names the
    // entry point. No data and no auth, so it also works as an uptime check.
    Route::get('/', fn () => response()->json([
        'name' => config('app.name'),
        'version' => 'v1',
        'status' => 'ok',
    ]));

    // Public Auth
    Route::post('auth/login', [AuthController::class, 'login']);

    // Protected Routes
    Route::middleware('auth:sanctum')->group(function () {

        // Auth
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/password', [AuthController::class, 'changePassword']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('auth/refresh', [AuthController::class, 'refreshToken']);

        // Dashboard
        Route::get('dashboard/main', [DashboardController::class, 'main']);
        Route::get('dashboard/branch', [DashboardController::class, 'branch']);

        // Stock
        Route::get('stock', [StockController::class, 'index']);
        Route::get('stock-mutations', [StockController::class, 'mutations']);
        Route::post('stock-mutations', [StockController::class, 'storeMutation']);
        Route::get('stock/closes', [StockController::class, 'closes']);
        Route::post('stock/close', [StockController::class, 'storeClose']);
        Route::get('stock/is-closed', [StockController::class, 'isTodayClosed']);

        // Delivery Orders — LEGACY, read-only. The DO flow is retired (N-GEN-7); only
        // index/show remain so historical documents stay readable (mobilex opens
        // GET /delivery-orders). Create/update and the status workflow are gone.
        // NOTE: only literal *GET* sub-paths would be swallowed by show(), so no extra care needed.
        Route::get('delivery-orders', [DeliveryOrderController::class, 'index']);
        Route::get('delivery-orders/{deliveryOrder}', [DeliveryOrderController::class, 'show']);

        // BALI-18…22 — cylinder reconciliation (PO vs SO vs delivered LO)
        Route::get('reconciliations', [ReconciliationController::class, 'index']);
        Route::post('reconciliations', [ReconciliationController::class, 'store']);
        // D6/D7 — outstanding credits/claims of a branch + Settle / Void (Pusat only).
        // NOTE: 'outstanding' must stay registered BEFORE {reconciliation}, otherwise
        // show() swallows the literal path.
        Route::get('reconciliations/outstanding', [ReconciliationController::class, 'outstanding']);
        Route::post('reconciliations/settlements/{settlement}/settle', [ReconciliationController::class, 'settle']);
        Route::post('reconciliations/settlements/{settlement}/void', [ReconciliationController::class, 'void']);
        Route::get('reconciliations/{reconciliation}', [ReconciliationController::class, 'show']);

        // Sales Orders (SO) — Main Branch to Pertamina
        Route::apiResource('sales-orders', SalesOrderController::class);
        Route::post('sales-orders/{deliveryOrder}/submit', [SalesOrderController::class, 'submit']);
        Route::post('sales-orders/{deliveryOrder}/approve', [SalesOrderController::class, 'approve']);
        Route::post('sales-orders/{deliveryOrder}/cancel', [SalesOrderController::class, 'cancel']);

        // Loading Orders (LO) — Main Branch loading instructions to Transportir
        Route::apiResource('loading-orders', LoadingOrderController::class);
        Route::post('loading-orders/{deliveryOrder}/submit', [LoadingOrderController::class, 'submit']);
        Route::post('loading-orders/{deliveryOrder}/approve', [LoadingOrderController::class, 'approve']);
        Route::post('loading-orders/{deliveryOrder}/mark-loaded', [LoadingOrderController::class, 'markLoaded']);
        Route::post('loading-orders/{deliveryOrder}/cancel', [LoadingOrderController::class, 'cancel']);

        // Purchase Orders (PO) — Other Branches to Main Branch
        Route::apiResource('purchase-orders', PurchaseOrderController::class);
        Route::post('purchase-orders/{deliveryOrder}/submit', [PurchaseOrderController::class, 'submit']);
        Route::post('purchase-orders/{deliveryOrder}/approve', [PurchaseOrderController::class, 'approve']);
        Route::post('purchase-orders/{deliveryOrder}/mark-in-transit', [PurchaseOrderController::class, 'markInTransit']);
        Route::post('purchase-orders/{deliveryOrder}/receive', [PurchaseOrderController::class, 'receive']);
        Route::post('purchase-orders/{deliveryOrder}/cancel', [PurchaseOrderController::class, 'cancel']);
        // D10 (2026-11-20): proof-of-delivery now belongs to the PO (the DO route is gone).
        Route::post('purchase-orders/{deliveryOrder}/receipt', [PurchaseOrderController::class, 'uploadReceipt']);

        // Sales
        Route::apiResource('sales', SalesController::class);

        // Costs
        Route::apiResource('costs', CostController::class);

        // Master Data
        Route::get('branches', [MasterDataController::class, 'branches']);
        Route::post('branches', [MasterDataController::class, 'storeBranch']);
        Route::put('branches/{branch}', [MasterDataController::class, 'updateBranch']);

        Route::get('expeditions', [MasterDataController::class, 'expeditions']);
        Route::post('expeditions', [MasterDataController::class, 'storeExpedition']);
        Route::put('expeditions/{expedition}', [MasterDataController::class, 'updateExpedition']);
        Route::delete('expeditions/{expedition}', [MasterDataController::class, 'destroyExpedition']);

        Route::get('transportirs', [MasterDataController::class, 'transportirs']);
        Route::post('transportirs', [MasterDataController::class, 'storeTransportir']);
        Route::put('transportirs/{transportir}', [MasterDataController::class, 'updateTransportir']);
        Route::delete('transportirs/{transportir}', [MasterDataController::class, 'destroyTransportir']);

        Route::get('prices', [MasterDataController::class, 'prices']);
        Route::get('prices/current', [MasterDataController::class, 'currentPrices']);
        // BALI-14: branch HPP / selling price after Transportir & Expedition costs.
        Route::get('prices/branch-cost-preview', [BranchPricingController::class, 'show']);
        Route::post('prices', [MasterDataController::class, 'storePrice']);

        Route::get('customers', [MasterDataController::class, 'customers']);
        Route::get('vehicles', [MasterDataController::class, 'vehicles']);

        Route::get('users', [MasterDataController::class, 'users']);
        Route::post('users', [MasterDataController::class, 'storeUser']);
        Route::put('users/{user}', [MasterDataController::class, 'updateUser']);
        Route::delete('users/{user}', [MasterDataController::class, 'destroyUser']);

        // Reports
        Route::get('reports/profit-loss', [ReportController::class, 'profitLoss']);
        Route::get('reports/stock-summary', [ReportController::class, 'stockSummary']);
        Route::get('reports/shipment-tracking', [ReportController::class, 'shipmentTracking']);
        Route::get('reports/sales-period', [ReportController::class, 'salesPeriod']);
        Route::get('reports/branch-ranking', [ReportController::class, 'branchRanking']);
        Route::get('reports/stock-audit', [ReportController::class, 'stockAudit']);
        Route::get('reports/hpp', [ReportController::class, 'hpp']);
        Route::get('reports/receivables-aging', [ReportController::class, 'receivablesAging']);
Route::get('reports/trial-balance', [ReportController::class, 'trialBalance']);

        // Scanner
        Route::post('scan', [ScanController::class, 'scan']);

        // Invoices
        Route::get('invoices', [InvoiceController::class, 'index']);
        Route::post('invoices', [InvoiceController::class, 'store']);
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);
        Route::put('invoices/{invoice}', [InvoiceController::class, 'update']);
        Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy']);
        Route::post('invoices/{invoice}/pay', [InvoiceController::class, 'pay']);
        Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue']);

        // Payables
        Route::get('payables', [PayableController::class, 'index']);
        Route::post('payables', [PayableController::class, 'store']);
        Route::get('payables/{payable}', [PayableController::class, 'show']);
        Route::put('payables/{payable}', [PayableController::class, 'update']);
        Route::delete('payables/{payable}', [PayableController::class, 'destroy']);
        Route::post('payables/{payable}/pay', [PayableController::class, 'pay']);
        // BALI-17 payment ledger
        Route::get('payables/{payable}/payments', [PayableController::class, 'payments']);
        Route::post('payables/{payable}/payments', [PayableController::class, 'storePayment']);
        Route::delete('payables/{payable}/payments/{payment}', [PayableController::class, 'destroyPayment']);

        // Cylinder Circulation
        // NOTE: 'summary' must be registered BEFORE the {cylinderCirculation}
        // show route, otherwise GET /summary is swallowed by show() and 404s.
        Route::get('cylinder-circulations', [CylinderCirculationController::class, 'index']);
        Route::post('cylinder-circulations', [CylinderCirculationController::class, 'store']);
        Route::get('cylinder-circulations/summary', [CylinderCirculationController::class, 'summary']);
        Route::get('cylinder-circulations/{cylinderCirculation}', [CylinderCirculationController::class, 'show']);
        Route::put('cylinder-circulations/{cylinderCirculation}', [CylinderCirculationController::class, 'update']);
        Route::delete('cylinder-circulations/{cylinderCirculation}', [CylinderCirculationController::class, 'destroy']);

        // Device Tokens
        Route::post('device/token', [DeviceController::class, 'registerToken']);
        Route::post('device/revoke', [DeviceController::class, 'revokeToken']);

        // Import / Export — Data
        Route::get('import/templates/{table}', [ImportController::class, 'downloadTemplate']);
        Route::post('import/{table}', [ImportController::class, 'import']);
    });
});
