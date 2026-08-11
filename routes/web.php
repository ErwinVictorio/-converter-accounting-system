<?php

use App\Http\Controllers\Dashboard;
use App\Http\Controllers\ManageBroker;
use App\Http\Controllers\ManageBrokerController;
use App\Http\Controllers\VatInputController;
use Illuminate\Support\Facades\Route;


Route::get('/',[Dashboard::class,'index']);

//  for record entry
Route::get('/records',[VatInputController::class,'index']);
Route::post('/vat-import',[VatInputController::class,'import']);

Route::get('/generate-datfile',[VatInputController::class,'generateDatFile']);


//  Route for Brokers
Route::get('/brokers',[ManageBrokerController::class,'index']);