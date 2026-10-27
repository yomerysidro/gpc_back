<?php

use App\Http\Controllers\User\UserController;
use App\Http\Controllers\Area\AreaController;
use App\Http\Controllers\Person\PersonaController;
use App\Http\Controllers\DocumentType\TipoDocumentoController;
use App\Http\Controllers\PersonSpeaker\PersonaPonenteController;
use App\Http\Controllers\PersonDocument\PersonaDocumentoController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// ============================================================
// USUARIOS
// ============================================================
Route::prefix('users')->group(function () {
    Route::get('/list',              [UserController::class, 'list']);
    Route::get('/detail/{idUser}',    [UserController::class, 'detail']);
    Route::post('/create',              [UserController::class, 'store']);
    Route::put('/update/{idUser}', [UserController::class, 'update']);
    Route::delete('/delete/{idUser}', [UserController::class, 'destroy']);
});
// ============================================================
// AREA
// ============================================================
Route::prefix('areas')->group(function () {
    Route::get('/list', [AreaController::class, 'list']);
    Route::get('/detail/{id}', [AreaController::class, 'detail']);
    Route::post('/create', [AreaController::class, 'store']);
    Route::put('/update/{id}', [AreaController::class, 'update']);
    Route::delete('/delete/{id}', [AreaController::class, 'destroy']);
});
// ============================================================
// PERSONA
// ============================================================
Route::prefix('persons')->group(function () {
    Route::get('/list', [PersonaController::class, 'list']);
    Route::get('/detail/{id}', [PersonaController::class, 'detail']);
    Route::post('/create', [PersonaController::class, 'store']);
    Route::put('/update/{id}', [PersonaController::class, 'update']);
    Route::delete('/delete/{id}', [PersonaController::class, 'destroy']);
});
// ============================================================
// TIPO DOCUMENTO
// ============================================================
Route::prefix('document-types')->group(function () {
    Route::get('/list', [TipoDocumentoController::class, 'list']);
    Route::get('/detail/{id}', [TipoDocumentoController::class, 'detail']);
    Route::post('/create', [TipoDocumentoController::class, 'store']);
    Route::put('/update/{id}', [TipoDocumentoController::class, 'update']);
    Route::delete('/delete/{id}', [TipoDocumentoController::class, 'destroy']);
});
// ============================================================
// PERSONA PONENTE
// ============================================================
Route::prefix('person-speakers')->group(function () {
    Route::get('/list', [PersonaPonenteController::class, 'list']);
    Route::get('/detail/{id}', [PersonaPonenteController::class, 'detail']);
    Route::post('/create', [PersonaPonenteController::class, 'store']);
    Route::put('/update/{id}', [PersonaPonenteController::class, 'update']);
    Route::delete('/delete/{id}', [PersonaPonenteController::class, 'destroy']);
});
// ============================================================
// PERSONA DOCUMENTO
// ============================================================
Route::prefix('person-documents')->group(function () {
    Route::get('/list', [PersonaDocumentoController::class, 'list']);
    Route::get('/detail/{id}', [PersonaDocumentoController::class, 'detail']);
    Route::post('/create', [PersonaDocumentoController::class, 'store']);
    Route::put('/update/{id}', [PersonaDocumentoController::class, 'update']);
    Route::delete('/delete/{id}', [PersonaDocumentoController::class, 'destroy']);
});
