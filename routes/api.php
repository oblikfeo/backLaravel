<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\PostController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| API v1 - Базовая авторизация
|
*/

Route::prefix('v1')->group(function () {
    // Публичные роуты (без авторизации)
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/posts', [PostController::class, 'index']); // Тестовый GET endpoint

    // Защищённые роуты (требуют авторизации)
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/user', [AuthController::class, 'user']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});
