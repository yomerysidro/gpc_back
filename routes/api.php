<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\User\UserController;
use Illuminate\Support\Facades\Route;

// ============================================================
// AUTENTICACIÓN (JWT en cookie httpOnly)
// ============================================================
Route::prefix('auth')->group(function () {
    Route::post('/login',           [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password',  [AuthController::class, 'resetPassword']);

    Route::middleware('auth.cookie')->group(function () {
        Route::get('/me',      [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

// ============================================================
// USUARIOS
// ============================================================
// Pendiente: reescribir UserController/UserData sobre acceso.usuario.
Route::prefix('users')->middleware(['auth.cookie', 'role:Administrador'])->group(function () {
    Route::get('/list',              [UserController::class, 'list']);
    Route::get('/detail/{idUser}',    [UserController::class, 'detail']);
    Route::post('/create',              [UserController::class, 'store']);
    Route::put('/update/{idUser}', [UserController::class, 'update']);
    Route::delete('/delete/{idUser}', [UserController::class, 'destroy']);
});