<?php

use App\Http\Controllers\ActivitySignature\ActivitySignatureController;
use App\Http\Controllers\Certificate\CertificateController;
use App\Http\Controllers\CertificateDocument\CertificateDocumentController;
use App\Http\Controllers\CertificateInquiry\CertificateInquiryController;
use App\Http\Controllers\CertificateSignature\CertificateSignatureController;
use App\Http\Controllers\CertificateType\CertificateTypeController;
use App\Http\Controllers\Recognition\RecognitionController;
use App\Http\Controllers\Signature\SignatureController;
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
