<?php

use App\Http\Controllers\Institution\InstitutionController;
use App\Http\Controllers\Person_Worker\Person_WorkerController;
use App\Http\Controllers\Practice\PracticeController;
use App\Http\Controllers\Practicing_Individual\Practicing_IndividualController;
use App\Http\Controllers\Supervisory_internship\Supervisory_internshipController;
use App\Http\Controllers\User\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// ============================================================
// USUARIOS
// ============================================================
Route::prefix('users')->group(function () {
    Route::get('/list', [UserController::class, 'list']);
    Route::get('/detail/{idUser}', [UserController::class, 'detail']);
    Route::post('/create', [UserController::class, 'store']);
    Route::put('/update/{idUser}', [UserController::class, 'update']);
    Route::delete('/delete/{idUser}', [UserController::class, 'destroy']);
});

// ============================================================
// Practica
Route::prefix('practices')->group(function () {
    Route::get('/list', [PracticeController::class, 'list']);
    Route::get('/detail/{idPractice}', [PracticeController::class, 'detail']);
    Route::post('/create', [PracticeController::class, 'store']);
    Route::put('/update/{idPractice}', [PracticeController::class, 'update']);
    Route::delete('/delete/{idPractice}', [PracticeController::class, 'destroy']);
});

// Institucion
// ============================================================
Route::prefix('institutions')->group(function () {
    Route::get('/list', [InstitutionController::class, 'list']);
    Route::get('/detail/{idInstitution}', [InstitutionController::class, 'detail']);
    Route::post('/create', [InstitutionController::class, 'store']);
    Route::put('/update/{idInstitution}', [InstitutionController::class, 'update']);
    Route::delete('/delete/{idInstitution}', [InstitutionController::class, 'destroy']);
});

// Practica_Supervisor
// ============================================================
Route::prefix('supervisory-internships')->group(function () {
    Route::get('/list', [Supervisory_internshipController::class, 'list']);
    Route::get('/detail/{idSupervisoryInternship}', [Supervisory_internshipController::class, 'detail']);
    Route::post('/create', [Supervisory_internshipController::class, 'store']);
    Route::put('/update/{idSupervisoryInternship}', [Supervisory_internshipController::class, 'update']);
    Route::delete('/delete/{idSupervisoryInternship}', [Supervisory_internshipController::class, 'destroy']);
});

// Persona_Practicante
// ============================================================

Route::prefix('practicing-individuals')->group(function () {
    Route::get('/list', [Practicing_IndividualController::class, 'list']);
    Route::get('/detail/{idPracticingIndividual}', [Practicing_IndividualController::class, 'detail']);
    Route::post('/create', [Practicing_IndividualController::class, 'store']);
    Route::put('/update/{idPracticingIndividual}', [Practicing_IndividualController::class, 'update']);
    Route::delete('/delete/{idPracticingIndividual}', [Practicing_IndividualController::class, 'destroy']);
});

// Persona_Trabajador
// ============================================================
Route::prefix('person-workers')->group(function () {
    Route::get('/list', [Person_WorkerController::class, 'list']);
    Route::get('/detail/{idPersonWorker}', [Person_WorkerController::class, 'detail']);
    Route::post('/create', [Person_WorkerController::class, 'store']);
    Route::put('/update/{idPersonWorker}', [Person_WorkerController::class, 'update']);
    Route::delete('/delete/{idPersonWorker}', [Person_WorkerController::class, 'destroy']);
});
