
<?php

use App\Http\Controllers\Api\ProductHistoryController;
use App\Http\Controllers\Api\StockLevelController;
use App\Models\Product;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\StockMovementController;



Route::middleware('tenant')->group(function () {

    Route::post(
        '/stock-movements',
        [StockMovementController::class, 'store']
    );

    Route::get(
        '/stock-levels',
        [StockLevelController::class, 'index']
    );

    Route::get(
        '/products/{sku}/history',
        [ProductHistoryController::class, 'index']
    );
});
