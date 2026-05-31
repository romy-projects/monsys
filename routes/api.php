<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CostController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DeliveryOrderController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\MasterDataController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SalesController;
use App\Http\Controllers\Api\ScanController;
use App\Http\Controllers\Api\StockController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::group([], function () {

    // Public Auth
    Route::post('auth/login', [AuthController::class, 'login']);

    // Protected Routes
    Route::middleware('auth:sanctum')->group(function () {

        // Auth
        Route::post('auth/logout', [AuthController::class, 'logout']);
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

        // Delivery Orders
        Route::apiResource('delivery-orders', DeliveryOrderController::class);
        Route::post('delivery-orders/{deliveryOrder}/submit', [DeliveryOrderController::class, 'submit']);
        Route::post('delivery-orders/{deliveryOrder}/approve', [DeliveryOrderController::class, 'approve']);
        Route::post('delivery-orders/{deliveryOrder}/mark-in-transit', [DeliveryOrderController::class, 'markInTransit']);
        Route::post('delivery-orders/{deliveryOrder}/receive', [DeliveryOrderController::class, 'receive']);
        Route::post('delivery-orders/{deliveryOrder}/cancel', [DeliveryOrderController::class, 'cancel']);

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

        Route::get('prices', [MasterDataController::class, 'prices']);
        Route::get('prices/current', [MasterDataController::class, 'currentPrices']);
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

        // Scanner
        Route::post('scan', [ScanController::class, 'scan']);

        // Device Tokens
        Route::post('device/token', [DeviceController::class, 'registerToken']);
        Route::post('device/revoke', [DeviceController::class, 'revokeToken']);

    });
});
