<?php

use Illuminate\Http\Request;
use Modules\Watchlist\Http\Controllers\Api\WatchlistApiController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get('/watchlist', function (Request $request) {
    return $request->user();
});

Route::middleware('auth:api')->group(function() {
    Route::get('/watchlist', [WatchlistApiController::class, 'index']);
});
