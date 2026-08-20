<?php

use App\Http\Controllers\Dashboard;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DatFileController;
use App\Http\Controllers\ImportationController;
use App\Http\Controllers\ManageBrokerController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\VatInputController;
use Illuminate\Support\Facades\Route;


Route::get('/',[Dashboard::class,'index']);

//  for record entry
Route::get('/records',[VatInputController::class,'index']);
Route::post('/vat-import',[VatInputController::class,'import']);
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
Route::post('/importation', [ImportationController::class, 'store']);
Route::put('/importation/{importationEntry}', [ImportationController::class, 'update']);
Route::delete('/importation/{importationEntry}', [ImportationController::class, 'destroy']);
