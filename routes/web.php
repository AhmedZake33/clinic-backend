<?php

use App\Http\Controllers\WhatsAppTestController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/test-whatsapp', [WhatsAppTestController::class, 'sendFromWeb']);
