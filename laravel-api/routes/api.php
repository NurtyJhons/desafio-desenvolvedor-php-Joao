<?php

use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\InstrumentoController;

Route::post('/upload', [UploadController::class, 'upload']);
Route::get('/uploads', [UploadController::class, 'historico']);

Route::get('/instrumentos_buscar', [InstrumentoController::class, 'buscar']);
