<?php

use App\Http\Controllers\ExportController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\ImportDownloadController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/export/sales',        [ExportController::class, 'sales'])->name('export.sales');
    Route::get('/export/pl',           [ExportController::class, 'profitLoss'])->name('export.pl');
    Route::get('/export/receivables',  [ExportController::class, 'receivables'])->name('export.receivables');
    Route::get('/export/journal',      [ExportController::class, 'journal'])->name('export.journal');
    Route::get('/export/sales-detail', [ExportController::class, 'salesDetail'])->name('export.sales_detail');

    Route::get('/admin/import-data/download/{file}', [ImportDownloadController::class, 'download'])
        ->name('import-data.download');

    // Phase 17 / T17-05 — session-only impersonation (registered before the
    // {user} route so that /impersonation/stop is not captured as a user id).
    Route::post('/impersonation/stop', [ImpersonationController::class, 'stop'])
        ->name('impersonation.stop');

    Route::post('/impersonation/{user}', [ImpersonationController::class, 'start'])
        ->whereNumber('user')
        ->middleware('can:impersonation.start')
        ->name('impersonation.start');
});
