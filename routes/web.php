<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Dashboard;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DatFileController;
use App\Http\Controllers\ImportationController;
use App\Http\Controllers\ManageBrokerController;
use App\Http\Controllers\RecordController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\VatInputController;
use App\Http\Controllers\WithholdingCompanyController;
use Illuminate\Support\Facades\Route;


// Authentication. The "login" name is what the auth middleware redirects to.
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');


Route::middleware('auth')->group(function () {
    Route::get('/',[Dashboard::class,'index']);

    //  for record entry
    Route::get('/records',[VatInputController::class,'index']);
    Route::post('/vat-import',[VatInputController::class,'import']);

    /*
     * Record: one page per data type, so no screen mixes four tables. Declared
     * ahead of the /records/{vatInput}/... routes -- those are three segments and
     * these are two, so they cannot collide, but keeping the literal paths first
     * makes that independent of Laravel's matching order.
     *
     * Each listing reads only its own storage; nothing here touches upload
     * parsing or DAT generation.
     */
    Route::get('/records/purchases', [RecordController::class, 'purchases'])->name('records.purchases.index');
    Route::get('/records/sales', [RecordController::class, 'sales'])->name('records.sales.index');
    Route::get('/records/expanded-wtax', [RecordController::class, 'expandedWtax'])->name('records.expanded-wtax.index');
    // The manual-entry module owns this listing; the Record page is just its table.
    Route::get('/records/importations', [ImportationController::class, 'records'])->name('records.importations.index');

    Route::get('/records/{vatInput}/adjusted-lookup', [VatInputController::class, 'adjustedLookup']);
    Route::get('/records/{vatInput}/edit', [VatInputController::class, 'edit']);
    Route::put('/records/{vatInput}', [VatInputController::class, 'update']);
    Route::put('/records/{vatInput}/bir-info', [VatInputController::class, 'updateBirInfo']);

    Route::get('/generate-datfile',[DatFileController::class,'index']);
    Route::get('/bir/company/{tin}',[DatFileController::class,'companyLookup']);
    Route::get('/download-datfile',[DatFileController::class,'download']);


    //  Route for Brokers
    Route::get('/brokers', [ManageBrokerController::class, 'index']);
    Route::post('/create', [ManageBrokerController::class, 'store']);
    Route::put('/brokers/{id}', [ManageBrokerController::class, 'update']);
    Route::delete('/brokers/{id}', [ManageBrokerController::class, 'destroy']);

    // Route for Suppliers
    Route::get('/suppliers', [SupplierController::class, 'index']);
    Route::post('/suppliers', [SupplierController::class, 'store']);
    Route::put('/suppliers/{id}', [SupplierController::class, 'update']);
    Route::delete('/suppliers/{id}', [SupplierController::class, 'destroy']);

    // Route for Customers
    Route::get('/customers', [CustomerController::class, 'index']);
    Route::post('/customers', [CustomerController::class, 'store']);
    Route::put('/customers/{id}', [CustomerController::class, 'update']);
    Route::delete('/customers/{id}', [CustomerController::class, 'destroy']);

    // Route for Importation (manual entry)
    Route::get('/importation', [ImportationController::class, 'index']);
    Route::get('/importation/template', [ImportationController::class, 'template']);
    Route::post('/importation', [ImportationController::class, 'store']);
    Route::post('/importation/upload', [ImportationController::class, 'upload']);
    Route::put('/importation/{importationEntry}', [ImportationController::class, 'update']);
    Route::delete('/importation/{importationEntry}', [ImportationController::class, 'destroy']);

    /*
     * Master Data > Companies: the withholding agent companies the Expanded
     * WTAX upload and the 1601EQ DAT are filed for. Deactivation is a PATCH rather
     * than the DELETE, because DELETE here means "remove a mistyped row" and is
     * refused once a month has been filed under the company.
     */
    Route::get('/withholding-companies', [WithholdingCompanyController::class, 'index']);
    Route::post('/withholding-companies', [WithholdingCompanyController::class, 'store']);  
    Route::put('/withholding-companies/{company}', [WithholdingCompanyController::class, 'update']);
    Route::patch('/withholding-companies/{company}/deactivate', [WithholdingCompanyController::class, 'deactivate']);
    Route::patch('/withholding-companies/{company}/activate', [WithholdingCompanyController::class, 'activate']);
    Route::delete('/withholding-companies/{company}', [WithholdingCompanyController::class, 'destroy']);
});
