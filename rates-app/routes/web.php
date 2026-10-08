<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/test', 'App\Http\Controllers\RatesController@index');
Route::get('/rate', 'App\Http\Controllers\RatesController@rate');
Route::get('/check', 'App\Http\Controllers\RatesController@check');
