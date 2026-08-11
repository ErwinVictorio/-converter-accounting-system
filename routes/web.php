<?php

use App\Http\Controllers\Dashboard;
use App\Http\Controllers\VatInputController;
use Illuminate\Support\Facades\Route;


Route::get('/',[Dashboard::class,'index']);

//  for record entry
Route::get('/records',[VatInputController::class,'index']);

Route::post('/vat-import',[VatInputController::class,'import']);


