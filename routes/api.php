<?php

use App\Http\Controllers\Activity\ActivityController;
use App\Http\Controllers\ActivityRequests\ActivityRequestController;
use App\Http\Controllers\ActivityRequests\ActivityRequestDecisionController;
use App\Http\Controllers\ActivitySession\ActivitySessionController;
use App\Http\Controllers\ActivitySignature\ActivitySignatureController;
use App\Http\Controllers\ActivitySpeaker\ActivitySpeakerController;
use App\Http\Controllers\ActivityType\ActivityTypeController;
use App\Http\Controllers\Area\AreaController;
use App\Http\Controllers\Attendance\AttendanceController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Certificate\CertificateController;
use App\Http\Controllers\CertificateDocument\CertificateDocumentController;
use App\Http\Controllers\CertificateInquiry\CertificateInquiryController;
use App\Http\Controllers\CertificateSignature\CertificateSignatureController;
use App\Http\Controllers\CertificateType\CertificateTypeController;
use App\Http\Controllers\DocumentType\TipoDocumentoController;
use App\Http\Controllers\Institution\InstitutionController;
use App\Http\Controllers\Module\ModuleController;
use App\Http\Controllers\Participation\ParticipationController;
use App\Http\Controllers\ParticipationType\ParticipationTypeController;
use App\Http\Controllers\Permission\PermissionController;
use App\Http\Controllers\Person\PersonaController;
use App\Http\Controllers\Person_Worker\Person_WorkerController;
use App\Http\Controllers\PersonDocument\PersonaDocumentoController;
use App\Http\Controllers\PersonSpeaker\PersonaPonenteController;
use App\Http\Controllers\Practice\PracticeController;
use App\Http\Controllers\Practicing_Individual\Practicing_IndividualController;
use App\Http\Controllers\Recognition\RecognitionController;
use App\Http\Controllers\Role\RoleController;
use App\Http\Controllers\Signature\SignatureController;
use App\Http\Controllers\Supervisory_internship\Supervisory_internshipController;
use App\Http\Controllers\User\UserController;
use App\Http\Middleware\ActivityRequests\AuthorizeActivityRequest;
use App\Http\Middleware\AuthorizeModuleOperation;
use Illuminate\Support\Facades\Route;

// ============================================================
// USUARIOS
// ============================================================
// Certificaciones y firmas: solo usuarios autenticados y autorizados por polÃƒÂ­ticas.
Route::middleware('auth.cookie')->group(function () {
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
Route::prefix('attendance')->middleware(['auth.cookie', AuthorizeModuleOperation::class.':App\Models\Attendance\Attendance'])->group(function () {
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
Route::prefix('activity')->middleware(['auth.cookie', AuthorizeModuleOperation::class.':App\Models\Activity\Activity'])->group(function () {
    Route::get('/', [ActivityController::class, 'list']);
    Route::get('/{idActivity}', [ActivityController::class, 'detail'])->whereNumber('idActivity');
    // Retained for compatibility: direct creation always returns 403; approval creates the activity.
    Route::post('/', [ActivityController::class, 'store']);
    Route::put('/{idActivity}', [ActivityController::class, 'update'])->whereNumber('idActivity');
    Route::patch('/{idActivity}', [ActivityController::class, 'update'])->whereNumber('idActivity');
    Route::delete('/{idActivity}', [ActivityController::class, 'destroy'])->whereNumber('idActivity');
});

// ============================================================
// SOLICITUDES DE ACTIVIDADES — JWT Y ATRIBUCIONES
// ============================================================
Route::prefix('activity-requests')->middleware(['auth.cookie', AuthorizeActivityRequest::class])->group(function () {
    Route::get('/authorized-areas', [ActivityRequestController::class, 'authorizedAreas']);
    Route::post('/', [ActivityRequestController::class, 'store']);
    Route::get('/mine', [ActivityRequestController::class, 'mine']);
    Route::get('/', [ActivityRequestController::class, 'index']);
    Route::get('/decisions/history', [ActivityRequestController::class, 'decisionHistory']);
    Route::get('/{requestId}', [ActivityRequestController::class, 'show'])->whereNumber('requestId');
    Route::put('/{requestId}', [ActivityRequestController::class, 'update'])->whereNumber('requestId');
    Route::post('/{requestId}/submit', [ActivityRequestController::class, 'submit'])->whereNumber('requestId');
    Route::get('/{requestId}/history', [ActivityRequestController::class, 'history'])->whereNumber('requestId');
    Route::get('/{requestId}/review-detail', [ActivityRequestController::class, 'reviewDetail'])->whereNumber('requestId');
    Route::post('/{requestId}/review', [ActivityRequestDecisionController::class, 'review'])->whereNumber('requestId');
    Route::post('/{requestId}/observe', [ActivityRequestDecisionController::class, 'observe'])->whereNumber('requestId');
    Route::post('/{requestId}/derive', [ActivityRequestDecisionController::class, 'derive'])->whereNumber('requestId');
    Route::post('/{requestId}/approve', [ActivityRequestDecisionController::class, 'approve'])->whereNumber('requestId');
    Route::post('/{requestId}/reject', [ActivityRequestDecisionController::class, 'reject'])->whereNumber('requestId');
});
// ============================================================
// SESIONES DE ACTIVIDAD
// ============================================================
Route::prefix('activity-session')->middleware(['auth.cookie', AuthorizeModuleOperation::class.':App\Models\ActivitySession\ActivitySession'])->group(function () {
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
Route::prefix('ativicty-type')->middleware(['auth.cookie', AuthorizeModuleOperation::class.':App\Models\ActivityType\ActivityType'])->group(function () {
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
Route::prefix('activity-speaker')->middleware(['auth.cookie', AuthorizeModuleOperation::class.':App\Models\ActivitySpeaker\ActivitySpeaker'])->group(function () {
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
Route::prefix('participation')->middleware(['auth.cookie', AuthorizeModuleOperation::class.':App\Models\Participation\Participation'])->group(function () {
    Route::get('/', [ParticipationController::class, 'list']);
    Route::get('/{idParticipation}', [ParticipationController::class, 'detail'])->whereNumber('idParticipation');
    Route::post('/', [ParticipationController::class, 'store']);
    Route::put('/{idParticipation}', [ParticipationController::class, 'update'])->whereNumber('idParticipation');
    Route::patch('/{idParticipation}', [ParticipationController::class, 'update'])->whereNumber('idParticipation');
    Route::delete('/{idParticipation}', [ParticipationController::class, 'destroy'])->whereNumber('idParticipation');
});

// ============================================================
// TIPOS DE PARTICIPACIÃ“N
// ============================================================
Route::prefix('participation-type')->middleware(['auth.cookie', AuthorizeModuleOperation::class.':App\Models\ParticipationType\ParticipationType'])->group(function () {
    Route::get('/', [ParticipationTypeController::class, 'list']);
    Route::get('/{idParticipationType}', [ParticipationTypeController::class, 'detail'])->whereNumber('idParticipationType');
    Route::post('/', [ParticipationTypeController::class, 'store']);
    Route::put('/{idParticipationType}', [ParticipationTypeController::class, 'update'])->whereNumber('idParticipationType');
    Route::patch('/{idParticipationType}', [ParticipationTypeController::class, 'update'])->whereNumber('idParticipationType');
    Route::delete('/{idParticipationType}', [ParticipationTypeController::class, 'destroy'])->whereNumber('idParticipationType');
});

// ============================================================
// AREA
// ============================================================
Route::prefix('areas')->middleware(['auth.cookie', AuthorizeModuleOperation::class.':App\Models\Area\Area'])->group(function () {
    Route::get('/list', [AreaController::class, 'list']);
    Route::get('/detail/{id}', [AreaController::class, 'detail']);
    Route::post('/create', [AreaController::class, 'store']);
    Route::put('/update/{id}', [AreaController::class, 'update']);
    Route::delete('/delete/{id}', [AreaController::class, 'destroy']);
});
// ============================================================
// PERSONA
// ============================================================
Route::prefix('persons')->middleware(['auth.cookie', AuthorizeModuleOperation::class.':App\Models\Person\Persona'])->group(function () {
    Route::get('/list', [PersonaController::class, 'list']);
    Route::get('/detail/{id}', [PersonaController::class, 'detail']);
    Route::post('/create', [PersonaController::class, 'store']);
    Route::put('/update/{id}', [PersonaController::class, 'update']);
    Route::delete('/delete/{id}', [PersonaController::class, 'destroy']);
});
// ============================================================
// TIPO DOCUMENTO
// ============================================================
Route::prefix('document-types')->middleware(['auth.cookie', AuthorizeModuleOperation::class.':App\Models\DocumentType\TipoDocumento'])->group(function () {
    Route::get('/list', [TipoDocumentoController::class, 'list']);
    Route::get('/detail/{id}', [TipoDocumentoController::class, 'detail']);
    Route::post('/create', [TipoDocumentoController::class, 'store']);
    Route::put('/update/{id}', [TipoDocumentoController::class, 'update']);
    Route::delete('/delete/{id}', [TipoDocumentoController::class, 'destroy']);
});
// ============================================================
// PERSONA PONENTE
// ============================================================
Route::prefix('person-speakers')->middleware(['auth.cookie', AuthorizeModuleOperation::class.':App\Models\PersonSpeaker\PersonaPonente'])->group(function () {
    Route::get('/list', [PersonaPonenteController::class, 'list']);
    Route::get('/detail/{id}', [PersonaPonenteController::class, 'detail']);
    Route::post('/create', [PersonaPonenteController::class, 'store']);
    Route::put('/update/{id}', [PersonaPonenteController::class, 'update']);
    Route::delete('/delete/{id}', [PersonaPonenteController::class, 'destroy']);
});
// ============================================================
// PERSONA DOCUMENTO
// ============================================================
Route::prefix('person-documents')->middleware(['auth.cookie', AuthorizeModuleOperation::class.':App\Models\PersonDocument\PersonaDocumento'])->group(function () {
    Route::get('/list', [PersonaDocumentoController::class, 'list']);
    Route::get('/detail/{id}', [PersonaDocumentoController::class, 'detail']);
    Route::post('/create', [PersonaDocumentoController::class, 'store']);
    Route::put('/update/{id}', [PersonaDocumentoController::class, 'update']);
    Route::delete('/delete/{id}', [PersonaDocumentoController::class, 'destroy']);
});

// ============================================================
// Practica
Route::prefix('practices')->middleware(['auth.cookie', AuthorizeModuleOperation::class.':App\Models\Practice\Practice'])->group(function () {
    Route::get('/list', [PracticeController::class, 'list']);
    Route::get('/detail/{idPractice}', [PracticeController::class, 'detail']);
    Route::post('/create', [PracticeController::class, 'store']);
    Route::put('/update/{idPractice}', [PracticeController::class, 'update']);
    Route::delete('/delete/{idPractice}', [PracticeController::class, 'destroy']);
});

// Institucion
// ============================================================
Route::prefix('institutions')->middleware(['auth.cookie', AuthorizeModuleOperation::class.':App\Models\Institution\Institution'])->group(function () {
    Route::get('/list', [InstitutionController::class, 'list']);
    Route::get('/detail/{idInstitution}', [InstitutionController::class, 'detail']);
    Route::post('/create', [InstitutionController::class, 'store']);
    Route::put('/update/{idInstitution}', [InstitutionController::class, 'update']);
    Route::delete('/delete/{idInstitution}', [InstitutionController::class, 'destroy']);
});

// Practica_Supervisor
// ============================================================
Route::prefix('supervisory-internships')->middleware(['auth.cookie', AuthorizeModuleOperation::class.':App\Models\Supervisory_internship\Supervisory_internship'])->group(function () {
    Route::get('/list', [Supervisory_internshipController::class, 'list']);
    Route::get('/detail/{idSupervisoryInternship}', [Supervisory_internshipController::class, 'detail']);
    Route::post('/create', [Supervisory_internshipController::class, 'store']);
    Route::put('/update/{idSupervisoryInternship}', [Supervisory_internshipController::class, 'update']);
    Route::delete('/delete/{idSupervisoryInternship}', [Supervisory_internshipController::class, 'destroy']);
});

// Persona_Practicante
// ============================================================

Route::prefix('practicing-individuals')->middleware(['auth.cookie', AuthorizeModuleOperation::class.':App\Models\Practicing_Individual\Practicing_Individual'])->group(function () {
    Route::get('/list', [Practicing_IndividualController::class, 'list']);
    Route::get('/detail/{idPracticingIndividual}', [Practicing_IndividualController::class, 'detail']);
    Route::post('/create', [Practicing_IndividualController::class, 'store']);
    Route::put('/update/{idPracticingIndividual}', [Practicing_IndividualController::class, 'update']);
    Route::delete('/delete/{idPracticingIndividual}', [Practicing_IndividualController::class, 'destroy']);
});

// Persona_Trabajador
// ============================================================
Route::prefix('person-workers')->middleware(['auth.cookie', AuthorizeModuleOperation::class.':App\Models\Person_Worker\Person_Worker'])->group(function () {
    Route::get('/list', [Person_WorkerController::class, 'list']);
    Route::get('/detail/{idPersonWorker}', [Person_WorkerController::class, 'detail']);
    Route::post('/create', [Person_WorkerController::class, 'store']);
    Route::put('/update/{idPersonWorker}', [Person_WorkerController::class, 'update']);
    Route::delete('/delete/{idPersonWorker}', [Person_WorkerController::class, 'destroy']);
});

// ============================================================
// AUTENTICACIÃ“N (JWT en cookie httpOnly)
// ============================================================
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    Route::middleware('auth.cookie')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

// ============================================================
// USUARIOS (acceso.usuario)
// ============================================================
Route::prefix('usuarios')->middleware('auth.cookie')->group(function () {
    Route::get('/', [UserController::class, 'list'])->middleware('permission:usuarios.ver');
    Route::get('/{id}', [UserController::class, 'detail'])->middleware('permission:usuarios.ver');
    Route::post('/', [UserController::class, 'store'])->middleware('permission:usuarios.crear');
    Route::put('/{id}', [UserController::class, 'update'])->middleware('permission:usuarios.editar');
    Route::patch('/{id}/estado', [UserController::class, 'updateStatus'])->middleware('permission:usuarios.editar');
    Route::patch('/{id}/desbloquear', [UserController::class, 'unlock'])->middleware('permission:usuarios.editar');
    Route::post('/{id}/restablecer-password', [UserController::class, 'resetPassword'])->middleware('permission:usuarios.editar');
});

// ============================================================
// ACCESO: roles, mÃ³dulos, permisos y sus asignaciones
// ============================================================
Route::middleware(['auth.cookie', 'role:Administrador'])->group(function () {
    Route::prefix('roles')->group(function () {
        Route::get('/', [RoleController::class, 'list']);
        Route::post('/', [RoleController::class, 'store']);
        Route::put('/{id}', [RoleController::class, 'update']);
        Route::patch('/{id}/estado', [RoleController::class, 'updateStatus']);
        Route::get('/{id}/modulos', [RoleController::class, 'modules']);
        Route::put('/{id}/modulos', [RoleController::class, 'syncModules']);
        Route::get('/{id}/permisos', [RoleController::class, 'permissions']);
        Route::put('/{id}/permisos', [RoleController::class, 'syncPermissions']);
    });

    Route::prefix('modulos')->group(function () {
        Route::get('/', [ModuleController::class, 'list']);
        Route::post('/', [ModuleController::class, 'store']);
        Route::put('/{id}', [ModuleController::class, 'update']);
        Route::patch('/{id}/estado', [ModuleController::class, 'updateStatus']);
        Route::post('/{id}/permisos', [PermissionController::class, 'store']);
    });

    Route::prefix('permisos')->group(function () {
        Route::put('/{id}', [PermissionController::class, 'update']);
        Route::patch('/{id}/estado', [PermissionController::class, 'updateStatus']);
    });
});
