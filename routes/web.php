<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/docs', fn () => view('docs'))->name('docs');

/** Debug only: Reverb WebSocket manual test UI */
Route::get('/websocket-test', fn () => view('websocket-test'))->name('websocket.test');
