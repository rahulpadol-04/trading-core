<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\WalletController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('auth:sanctum')->group(function (): void {
    Route::post('orders', [OrderController::class, 'store'])->middleware('throttle:orders')->name('orders.store');
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::delete('orders/{order}', [OrderController::class, 'destroy'])->name('orders.destroy');

    Route::get('wallet', WalletController::class)->name('wallet.show');
});
