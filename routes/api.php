<?php

use App\Http\Controllers\Activity\ActivityController;
use App\Http\Controllers\ActivitySession\ActivitySessionController;
use App\Http\Controllers\ActivitySpeaker\ActivitySpeakerController;
use App\Http\Controllers\ActivityType\ActivityTypeController;
use App\Http\Controllers\Attendance\AttendanceController;
use App\Http\Controllers\Participation\ParticipationController;
use App\Http\Controllers\ParticipationType\ParticipationTypeController;
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
    Route::patch('/update/{idUser}', [UserController::class, 'update']);
    Route::delete('/delete/{idUser}', [UserController::class, 'destroy']);
});

// ============================================================
// ASISTENCIAS
// ============================================================
Route::prefix('attendance')->group(function () {
    Route::get('/list', [AttendanceController::class, 'list']);
    Route::get('/{idAttendance}', [AttendanceController::class, 'detail'])->whereNumber('idAttendance');
    Route::post('/create', [AttendanceController::class, 'store']);
    Route::put('/update/{idAttendance}', [AttendanceController::class, 'update'])->whereNumber('idAttendance');
    Route::patch('/update/{idAttendance}', [AttendanceController::class, 'update'])->whereNumber('idAttendance');
    Route::delete('/delete/{idAttendance}', [AttendanceController::class, 'destroy'])->whereNumber('idAttendance');
});

// ============================================================
// ACTIVIDADES
// ============================================================
Route::prefix('activity')->group(function () {
    Route::get('/', [ActivityController::class, 'list']);
    Route::get('/{idActivity}', [ActivityController::class, 'detail'])->whereNumber('idActivity');
    Route::post('/', [ActivityController::class, 'store']);
    Route::put('/{idActivity}', [ActivityController::class, 'update'])->whereNumber('idActivity');
    Route::patch('/{idActivity}', [ActivityController::class, 'update'])->whereNumber('idActivity');
    Route::delete('/{idActivity}', [ActivityController::class, 'destroy'])->whereNumber('idActivity');
});

// ============================================================
// SESIONES DE ACTIVIDAD
// ============================================================
Route::prefix('activity-session')->group(function () {
    Route::get('/', [ActivitySessionController::class, 'list']);
    Route::get('/{idActivitySession}', [ActivitySessionController::class, 'detail'])->whereNumber('idActivitySession');
    Route::post('/', [ActivitySessionController::class, 'store']);
    Route::put('/{idActivitySession}', [ActivitySessionController::class, 'update'])->whereNumber('idActivitySession');
    Route::patch('/{idActivitySession}', [ActivitySessionController::class, 'update'])->whereNumber('idActivitySession');
    Route::delete('/{idActivitySession}', [ActivitySessionController::class, 'destroy'])->whereNumber('idActivitySession');
});

// ============================================================
// TIPOS DE ACTIVIDAD
// ============================================================
Route::prefix('ativicty-type')->group(function () {
    Route::get('/', [ActivityTypeController::class, 'list']);
    Route::get('/{idActivityType}', [ActivityTypeController::class, 'detail'])->whereNumber('idActivityType');
    Route::post('/', [ActivityTypeController::class, 'store']);
    Route::put('/{idActivityType}', [ActivityTypeController::class, 'update'])->whereNumber('idActivityType');
    Route::patch('/{idActivityType}', [ActivityTypeController::class, 'update'])->whereNumber('idActivityType');
    Route::delete('/{idActivityType}', [ActivityTypeController::class, 'destroy'])->whereNumber('idActivityType');
});

// ============================================================
// PONENTES DE ACTIVIDADES
// ============================================================
Route::prefix('activity-speaker')->group(function () {
    Route::get('/', [ActivitySpeakerController::class, 'list']);
    Route::get('/{idActivitySpeaker}', [ActivitySpeakerController::class, 'detail'])->whereNumber('idActivitySpeaker');
    Route::post('/', [ActivitySpeakerController::class, 'store']);
    Route::put('/{idActivitySpeaker}', [ActivitySpeakerController::class, 'update'])->whereNumber('idActivitySpeaker');
    Route::patch('/{idActivitySpeaker}', [ActivitySpeakerController::class, 'update'])->whereNumber('idActivitySpeaker');
    Route::delete('/{idActivitySpeaker}', [ActivitySpeakerController::class, 'destroy'])->whereNumber('idActivitySpeaker');
});

// ============================================================
// PARTICIPACIONES
// ============================================================
Route::prefix('participation')->group(function () {
    Route::get('/', [ParticipationController::class, 'list']);
    Route::get('/{idParticipation}', [ParticipationController::class, 'detail'])->whereNumber('idParticipation');
    Route::post('/', [ParticipationController::class, 'store']);
    Route::put('/{idParticipation}', [ParticipationController::class, 'update'])->whereNumber('idParticipation');
    Route::patch('/{idParticipation}', [ParticipationController::class, 'update'])->whereNumber('idParticipation');
    Route::delete('/{idParticipation}', [ParticipationController::class, 'destroy'])->whereNumber('idParticipation');
});

// ============================================================
// TIPOS DE PARTICIPACIÓN
// ============================================================
Route::prefix('participation-type')->group(function () {
    Route::get('/', [ParticipationTypeController::class, 'list']);
    Route::get('/{idParticipationType}', [ParticipationTypeController::class, 'detail'])->whereNumber('idParticipationType');
    Route::post('/', [ParticipationTypeController::class, 'store']);
    Route::put('/{idParticipationType}', [ParticipationTypeController::class, 'update'])->whereNumber('idParticipationType');
    Route::patch('/{idParticipationType}', [ParticipationTypeController::class, 'update'])->whereNumber('idParticipationType');
    Route::delete('/{idParticipationType}', [ParticipationTypeController::class, 'destroy'])->whereNumber('idParticipationType');
});
