<?php

use App\Http\Controllers\ExportController;
use App\Http\Controllers\ImportDownloadController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/export/sales',        [ExportController::class, 'sales'])->name('export.sales');
    Route::get('/export/pl',           [ExportController::class, 'profitLoss'])->name('export.pl');
    Route::get('/export/receivables',  [ExportController::class, 'receivables'])->name('export.receivables');

    Route::get('/admin/import-data/download/{file}', [ImportDownloadController::class, 'download'])
        ->name('import-data.download');
});
