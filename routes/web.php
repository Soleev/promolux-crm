<?php

use App\Http\Controllers\CrmDocumentController;
use App\Http\Controllers\CrmExportController;
use App\Http\Controllers\RolePreviewController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('filament.admin.home');
});

Route::middleware('auth')->group(function () {
    Route::post('/crm/preview-role', [RolePreviewController::class, 'start'])->name('crm.preview.start');
    Route::post('/crm/exit-preview', [RolePreviewController::class, 'stop'])->name('crm.preview.stop');
    Route::get('/crm-documents/{type}/{id}', CrmDocumentController::class)->name('crm.document');
    Route::get('/crm-register.csv', CrmExportController::class)->name('crm.export');
});
