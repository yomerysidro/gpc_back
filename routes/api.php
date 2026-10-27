<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Module\ModuleController;
use App\Http\Controllers\Permission\PermissionController;
use App\Http\Controllers\Role\RoleController;
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

// ============================================================
// ACCESO: roles, módulos, permisos y sus asignaciones
// ============================================================
Route::middleware(['auth.cookie', 'role:Administrador'])->group(function () {
    Route::prefix('roles')->group(function () {
        Route::get('/',               [RoleController::class, 'list']);
        Route::post('/',              [RoleController::class, 'store']);
        Route::put('/{id}',           [RoleController::class, 'update']);
        Route::patch('/{id}/estado',  [RoleController::class, 'updateStatus']);
        Route::get('/{id}/modulos',   [RoleController::class, 'modules']);
        Route::put('/{id}/modulos',   [RoleController::class, 'syncModules']);
        Route::get('/{id}/permisos',  [RoleController::class, 'permissions']);
        Route::put('/{id}/permisos',  [RoleController::class, 'syncPermissions']);
    });

    Route::prefix('modulos')->group(function () {
        Route::get('/',               [ModuleController::class, 'list']);
        Route::post('/',              [ModuleController::class, 'store']);
        Route::put('/{id}',           [ModuleController::class, 'update']);
        Route::patch('/{id}/estado',  [ModuleController::class, 'updateStatus']);
        Route::post('/{id}/permisos', [PermissionController::class, 'store']);
    });

    Route::prefix('permisos')->group(function () {
        Route::put('/{id}',           [PermissionController::class, 'update']);
        Route::patch('/{id}/estado',  [PermissionController::class, 'updateStatus']);
    });
});

