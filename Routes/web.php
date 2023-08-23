<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

use Illuminate\Support\Facades\Route;
use Modules\Watchlist\Http\Controllers\Api\WatchlistApiController;

Route::middleware(['auth:sanctum', 'atmosphere.teamed', 'verified'])->prefix('finance')->group(function() {
    Route::resource('/watchlist', 'WatchlistController');
});

Route::middleware(['auth:sanctum', 'atmosphere.teamed', 'verified'])->prefix('api')->group(function() {
    Route::get('/finance/watchlist', [WatchlistApiController::class, 'index']);
});
