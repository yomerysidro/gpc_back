<?php

use App\Http\Controllers\Activity\ActivityController;
use App\Http\Controllers\ActivitySession\ActivitySessionController;
use App\Http\Controllers\ActivitySignature\ActivitySignatureController;
use App\Http\Controllers\ActivitySpeaker\ActivitySpeakerController;
use App\Http\Controllers\ActivityType\ActivityTypeController;
use App\Http\Controllers\Attendance\AttendanceController;
use App\Http\Controllers\Certificate\CertificateController;
use App\Http\Controllers\CertificateDocument\CertificateDocumentController;
use App\Http\Controllers\CertificateInquiry\CertificateInquiryController;
use App\Http\Controllers\CertificateSignature\CertificateSignatureController;
use App\Http\Controllers\CertificateType\CertificateTypeController;
use App\Http\Controllers\Participation\ParticipationController;
use App\Http\Controllers\ParticipationType\ParticipationTypeController;
use App\Http\Controllers\Recognition\RecognitionController;
use App\Http\Controllers\Signature\SignatureController;
use App\Http\Controllers\User\UserController;
use App\Http\Middleware\AuthorizeModuleOperation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// ============================================================
// USUARIOS
// ============================================================
Route::prefix('users')->middleware(['auth:sanctum', AuthorizeModuleOperation::class.':App\Models\User'])->group(function () {
    Route::get('/list', [UserController::class, 'list']);
    Route::get('/detail/{idUser}', [UserController::class, 'detail']);
    Route::post('/create', [UserController::class, 'store']);
    Route::put('/update/{idUser}', [UserController::class, 'update']);
    Route::patch('/update/{idUser}', [UserController::class, 'update']);
    Route::delete('/delete/{idUser}', [UserController::class, 'destroy']);
});

// Certificaciones y firmas: solo usuarios autenticados y autorizados por polÃ­ticas.
Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('tipos-certificado')->group(function () {
        Route::get('/list', [CertificateTypeController::class, 'list']);
        Route::get('/detail/{idTipoCertificado}', [CertificateTypeController::class, 'detail']);
        Route::post('/create', [CertificateTypeController::class, 'store']);
        Route::put('/update/{idTipoCertificado}', [CertificateTypeController::class, 'update']);
        Route::patch('/update/{idTipoCertificado}', [CertificateTypeController::class, 'update']);
        Route::delete('/delete/{idTipoCertificado}', [CertificateTypeController::class, 'destroy']);
    });
    Route::prefix('certificados')->group(function () {
        Route::get('/list', [CertificateController::class, 'list']);
        Route::get('/detail/{idCertificado}', [CertificateController::class, 'detail']);
        Route::post('/create', [CertificateController::class, 'store']);
        Route::put('/update/{idCertificado}', [CertificateController::class, 'update']);
        Route::patch('/update/{idCertificado}', [CertificateController::class, 'update']);
        Route::delete('/delete/{idCertificado}', [CertificateController::class, 'destroy']);
    });
    Route::prefix('reconocimientos')->group(function () {
        Route::get('/list', [RecognitionController::class, 'list']);
        Route::get('/detail/{idReconocimiento}', [RecognitionController::class, 'detail']);
        Route::post('/create', [RecognitionController::class, 'store']);
        Route::put('/update/{idReconocimiento}', [RecognitionController::class, 'update']);
        Route::patch('/update/{idReconocimiento}', [RecognitionController::class, 'update']);
        Route::delete('/delete/{idReconocimiento}', [RecognitionController::class, 'destroy']);
    });
    Route::prefix('certificados-documentos')->group(function () {
        Route::get('/list', [CertificateDocumentController::class, 'list']);
        Route::get('/detail/{idCertificadoDocumento}', [CertificateDocumentController::class, 'detail']);
        Route::post('/create', [CertificateDocumentController::class, 'store']);
        Route::put('/update/{idCertificadoDocumento}', [CertificateDocumentController::class, 'update']);
        Route::patch('/update/{idCertificadoDocumento}', [CertificateDocumentController::class, 'update']);
        Route::delete('/delete/{idCertificadoDocumento}', [CertificateDocumentController::class, 'destroy']);
    });
    Route::prefix('firmas')->group(function () {
        Route::get('/list', [SignatureController::class, 'list']);
        Route::get('/detail/{idFirma}', [SignatureController::class, 'detail']);
        Route::post('/create', [SignatureController::class, 'store']);
        Route::put('/update/{idFirma}', [SignatureController::class, 'update']);
        Route::patch('/update/{idFirma}', [SignatureController::class, 'update']);
        Route::delete('/delete/{idFirma}', [SignatureController::class, 'destroy']);
    });
    Route::prefix('certificados-firmas')->group(function () {
        Route::get('/list', [CertificateSignatureController::class, 'list']);
        Route::get('/detail/{idCertificadoFirma}', [CertificateSignatureController::class, 'detail']);
        Route::post('/create', [CertificateSignatureController::class, 'store']);
        Route::put('/update/{idCertificadoFirma}', [CertificateSignatureController::class, 'update']);
        Route::patch('/update/{idCertificadoFirma}', [CertificateSignatureController::class, 'update']);
        Route::delete('/delete/{idCertificadoFirma}', [CertificateSignatureController::class, 'destroy']);
    });
    Route::prefix('consultas-certificado')->group(function () {
        Route::get('/list', [CertificateInquiryController::class, 'list']);
        Route::get('/detail/{idConsultaCertificado}', [CertificateInquiryController::class, 'detail']);
        Route::post('/create', [CertificateInquiryController::class, 'store']);
        Route::put('/update/{idConsultaCertificado}', [CertificateInquiryController::class, 'update']);
        Route::patch('/update/{idConsultaCertificado}', [CertificateInquiryController::class, 'update']);
        Route::delete('/delete/{idConsultaCertificado}', [CertificateInquiryController::class, 'destroy']);
    });
    Route::prefix('actividades-firmas')->group(function () {
        Route::get('/list', [ActivitySignatureController::class, 'list']);
        Route::get('/detail/{idActividadFirma}', [ActivitySignatureController::class, 'detail']);
        Route::post('/create', [ActivitySignatureController::class, 'store']);
        Route::put('/update/{idActividadFirma}', [ActivitySignatureController::class, 'update']);
        Route::patch('/update/{idActividadFirma}', [ActivitySignatureController::class, 'update']);
        Route::delete('/delete/{idActividadFirma}', [ActivitySignatureController::class, 'destroy']);
    });
});

// ============================================================
// ASISTENCIAS
// ============================================================
Route::prefix('attendance')->middleware(['auth:sanctum', AuthorizeModuleOperation::class.':App\Models\Attendance\Attendance'])->group(function () {
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
Route::prefix('activity')->middleware(['auth:sanctum', AuthorizeModuleOperation::class.':App\Models\Activity\Activity'])->group(function () {
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
Route::prefix('activity-session')->middleware(['auth:sanctum', AuthorizeModuleOperation::class.':App\Models\ActivitySession\ActivitySession'])->group(function () {
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
Route::prefix('ativicty-type')->middleware(['auth:sanctum', AuthorizeModuleOperation::class.':App\Models\ActivityType\ActivityType'])->group(function () {
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
Route::prefix('activity-speaker')->middleware(['auth:sanctum', AuthorizeModuleOperation::class.':App\Models\ActivitySpeaker\ActivitySpeaker'])->group(function () {
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
Route::prefix('participation')->middleware(['auth:sanctum', AuthorizeModuleOperation::class.':App\Models\Participation\Participation'])->group(function () {
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
Route::prefix('participation-type')->middleware(['auth:sanctum', AuthorizeModuleOperation::class.':App\Models\ParticipationType\ParticipationType'])->group(function () {
    Route::get('/', [ParticipationTypeController::class, 'list']);
    Route::get('/{idParticipationType}', [ParticipationTypeController::class, 'detail'])->whereNumber('idParticipationType');
    Route::post('/', [ParticipationTypeController::class, 'store']);
    Route::put('/{idParticipationType}', [ParticipationTypeController::class, 'update'])->whereNumber('idParticipationType');
    Route::patch('/{idParticipationType}', [ParticipationTypeController::class, 'update'])->whereNumber('idParticipationType');
    Route::delete('/{idParticipationType}', [ParticipationTypeController::class, 'destroy'])->whereNumber('idParticipationType');
});
