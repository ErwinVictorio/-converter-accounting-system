<?php

use App\Http\Controllers\Dashboard;
use App\Http\Controllers\DatFileController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecordEntryController;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;


Route::get('/',[Dashboard::class,'index']);

//  for record entry
Route::get('/records',[RecordEntryController::class,'index']);
Route::get('/generate-datfile',[DatFileController::class,'index']);
Route::post('/create-record',[RecordEntryController::class,'store']);
Route::get('/download-datfile',[DatFileController::class,'download']);
 

