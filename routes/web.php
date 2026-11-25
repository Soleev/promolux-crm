<?php

use App\Http\Controllers\CrmDocumentController;
use App\Http\Controllers\CrmExportController;
use App\Http\Controllers\SfpScreenController;
use App\Http\Controllers\SfpLoginController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('filament.admin.home');
});

Route::middleware('auth')->group(function () {

    Route::get('/crm-documents/{type}/{id}', CrmDocumentController::class)->name('crm.document');
    Route::get('/crm-register.csv', CrmExportController::class)->name('crm.export');
});

Route::get('/crm/sfp/login', [SfpLoginController::class, 'show'])->name('sfp.login');
Route::post('/crm/sfp/login', [SfpLoginController::class, 'login'])->middleware('throttle:5,1')->name('sfp.login.submit');
Route::post('/crm/sfp/logout', [SfpLoginController::class, 'logout'])->middleware('auth')->name('sfp.logout');
Route::get('/crm/sfp', SfpScreenController::class)->middleware('auth')->name('sfp.screen');
