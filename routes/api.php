<?php

use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'notifications', 'as' => 'notifications.'], function (): void {
    Route::post('/', [NotificationController::class, 'store'])->name('store');
    Route::post('/batch', [NotificationController::class, 'storeBatch'])->name('store.batch');
    Route::get('/', [NotificationController::class, 'index'])->name('index');
    Route::get('/batch/{batchId}', [NotificationController::class, 'showBatch'])->name('show.batch');
    Route::get('/{notification}', [NotificationController::class, 'show'])->name('show');
    Route::patch('/{notification}/cancel', [NotificationController::class, 'cancel'])->name('cancel');
});
