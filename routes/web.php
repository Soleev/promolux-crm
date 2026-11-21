<?php

use App\Http\Controllers\CrmDocumentController;
use App\Http\Controllers\CrmExportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('filament.admin.home');
});

Route::middleware('auth')->group(function () {

    Route::get('/crm-documents/{type}/{id}', CrmDocumentController::class)->name('crm.document');
    Route::get('/crm-register.csv', CrmExportController::class)->name('crm.export');
});
