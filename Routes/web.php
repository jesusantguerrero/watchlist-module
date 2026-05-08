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
use Modules\Watchlist\Http\Controllers\SharedWatchlistController;

Route::middleware(['auth:sanctum', 'atmosphere.teamed', 'verified'])->prefix('finance')->group(function() {
    Route::resource('/watchlist', 'WatchlistController');
    Route::post('/watchlist/{watchlist}/share', [SharedWatchlistController::class, 'store'])
        ->name('watchlist.share.store');
    Route::delete('/watchlist/{watchlist}/share', [SharedWatchlistController::class, 'destroy'])
        ->name('watchlist.share.destroy');
});

Route::middleware(['auth:sanctum', 'atmosphere.teamed', 'verified'])->prefix('api')->group(function() {
    Route::get('/finance/watchlist', [WatchlistApiController::class, 'index']);
});

// Public share view — no auth, token in path. Mirrors shared shopping list pattern.
Route::get('/share/watchlist/{token}', [SharedWatchlistController::class, 'show'])
    ->name('watchlist.shared.show');
