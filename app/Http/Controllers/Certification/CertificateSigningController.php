<?php

namespace App\Http\Controllers\Certification;

use App\Http\Controllers\Controller;
use App\Models\Certificate\Certificate;
use App\Models\CertificateDocument\CertificateDocument;
use App\Services\CertificateDocumentGenerator;
use App\Services\CertificateDocumentWorkflow;
use App\Services\CertificateSignerRevision;
use App\Services\CertificateSigningDemo;
use App\Services\CertificateSigningStageB;
use App\Traits\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class CertificateSigningController extends Controller
{
    use ApiResponse;

    public function __construct(private CertificateSigningStageB $stageB, private CertificateSigningDemo $demo) {}

    public function progress(Request $request, int $id): JsonResponse
    {
        return $this->respond(fn (): array => $this->stageB->progress($id, $request->user()));
    }

    public function availableSigners(Request $request, int $id): JsonResponse
    {
        return $this->respond(fn (): array => $this->stageB->availableSigners($id, $request->user()));
    }

    public function verification(Request $request, int $id): JsonResponse
    {
        return $this->respond(fn (): array => $this->stageB->verification($id, $request->user()));
    }

    public function reviewPdf(Request $request, int $id, CertificateSignerRevision $revision): JsonResponse
    {
        return $this->respond(function () use ($request, $id, $revision): array {
            $input = $this->input($request, [
                'mensaje' => 'sometimes|string|max:1200',
                'modalidad' => 'required_with:firmantes|in:PARALELA,SECUENCIAL',
                'firmantes' => 'required_with:modalidad|array|min:1|max:8',
                'firmantes.*' => 'required|array:id_persona_trabajador,orden_firma',
                'firmantes.*.id_persona_trabajador' => 'required|integer|min:1|distinct',
                'firmantes.*.orden_firma' => 'required|integer|min:1|distinct',
            ]);
            $selection = isset($input['firmantes']) ? array_map(fn (array $signer): array => [
                'id_persona_trabajador' => (int) $signer['id_persona_trabajador'], 'orden_firma' => (int) $signer['orden_firma'],
            ], $input['firmantes']) : null;

            return $revision->review($id, $request->user(), $input['mensaje'] ?? null, $selection, $input['modalidad'] ?? null);
        });
    }

    public function cancelPdf(Request $request, int $id, CertificateSignerRevision $revision): JsonResponse
    {
        return $this->respond(function () use ($request, $id, $revision): array {
            $input = $this->input($request, ['token_revision' => 'required|string|regex:/^[a-f0-9]{64}$/D']);

            return $revision->cancel($id, $request->user(), $input['token_revision']);
        });
    }

    public function confirmPdf(Request $request, int $id, CertificateSignerRevision $revision): JsonResponse
    {
        return $this->respond(function () use ($request, $id, $revision): array {
            $input = $this->input($request, ['token_revision' => 'required|string|regex:/^[a-f0-9]{64}$/D', 'confirmar' => 'required|accepted',
                'hash_pdf' => 'sometimes|string|regex:/^[a-f0-9]{64}$/D']);

            return $revision->confirm($id, $request->user(), $input['token_revision'], $input['hash_pdf'] ?? null);
        });
    }

    public function authorizedPdf(Request $request, int $id, CertificateDocumentWorkflow $workflow, CertificateDocumentGenerator $generator): Response
    {
        try {
            $document = $generator->authorizedArtifact($workflow->document($id, $request->user()));

            return response()->download(Storage::disk('local')->path($document->ruta_archivo),
                'certificado-'.$document->codigo_verificacion.'.pdf', ['Content-Type' => 'application/pdf',
                    'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
                    'X-Document-SHA256' => $document->hash_certificado]);
        } catch (HttpException $exception) {
            return $this->error($exception->getMessage(), $exception->getStatusCode());
        } catch (Throwable $exception) {
            Log::error('certificado.pdf.consulta_fallida', ['tipo' => $exception::class]);

            return $this->error('No se pudo consultar el PDF autorizado.', 500);
        }
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        return $this->respond(function () use ($request, $id): array {
            $input = $this->input($request, [
                'modalidad' => 'required|in:PARALELA,SECUENCIAL',
                'firmantes' => 'required|array|min:1|max:100',
                'firmantes.*' => 'required|array:id_firma,id_persona_trabajador,orden_firma',
                'firmantes.*.id_firma' => 'required_without:firmantes.*.id_persona_trabajador|prohibits:firmantes.*.id_persona_trabajador|integer|min:1|distinct',
                'firmantes.*.id_persona_trabajador' => 'required_without:firmantes.*.id_firma|prohibits:firmantes.*.id_firma|integer|min:1|distinct',
                'firmantes.*.orden_firma' => 'required|integer|min:1|distinct',
                'confirmar' => 'required|accepted',
            ]);

            $signers = array_map(fn (array $signer): array => [
                ...(isset($signer['id_persona_trabajador']) ? ['id_persona_trabajador' => (int) $signer['id_persona_trabajador']]
                    : ['id_firma' => (int) $signer['id_firma']]),
                'orden_firma' => (int) $signer['orden_firma'],
            ], $input['firmantes']);

            return $this->stageB->assign($id, $request->user(), $input['modalidad'], $signers);
        });
    }

    public function sign(Request $request, int $id): JsonResponse
    {
        return $this->respond(function () use ($request, $id): array {
            $this->input($request, ['id_firma' => 'required|integer|min:1', 'confirmar' => 'required|accepted']);

            return $this->stageB->blockedOperation($id, 'firmar', $request->user());
        }, blocked: true);
    }

    public function issue(Request $request, int $id): JsonResponse
    {
        return $this->respond(function () use ($request, $id): array {
            $this->input($request, ['confirmar' => 'required|accepted']);

            return $this->stageB->blockedOperation($id, 'emitir', $request->user());
        }, blocked: true);
    }

    public function finalDownload(Request $request, int $id): JsonResponse
    {
        return $this->respond(fn (): array => $this->stageB->blockedOperation($id, 'descargar_final', $request->user()), blocked: true);
    }

    public function batchProgress(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request): array {
            $input = $this->input($request, [
                'ids_documentos' => 'required|array|min:1|max:100',
                'ids_documentos.*' => 'required|integer|min:1|distinct',
            ]);

            return $this->stageB->batchProgress(array_map('intval', $input['ids_documentos']), $request->user());
        });
    }

    public function demoProgress(Request $request, int $id): JsonResponse
    {
        return $this->respond(fn (): array => $this->demo->progress($id, $request->user()));
    }

    public function demoAssign(Request $request, int $id): JsonResponse
    {
        return $this->respond(function () use ($request, $id): array {
            $input = $this->input($request, [
                'modalidad' => 'required|in:PARALELA,SECUENCIAL',
                'firmantes' => 'required|array|min:1|max:3',
                'firmantes.*' => 'required|array:id_demo,orden_firma',
                'firmantes.*.id_demo' => 'required|in:DEMO-1,DEMO-2,DEMO-3|distinct',
                'firmantes.*.orden_firma' => 'required|integer|min:1|distinct',
                'clave_operacion' => 'required|uuid', 'confirmar' => 'required|accepted',
            ]);
            $signers = array_map(fn (array $signer): array => [
                'id_demo' => $signer['id_demo'], 'orden_firma' => (int) $signer['orden_firma'],
            ], $input['firmantes']);

            return $this->demo->assign($id, $request->user(), $input['modalidad'], $signers, $input['clave_operacion']);
        });
    }

    public function demoSimulate(Request $request, int $id): JsonResponse
    {
        return $this->respond(function () use ($request, $id): array {
            $input = $this->input($request, [
                'id_demo' => 'required|in:DEMO-1,DEMO-2,DEMO-3',
                'clave_operacion' => 'required|uuid', 'confirmar' => 'required|accepted',
            ]);

            return $this->demo->simulate($id, $request->user(), $input['id_demo'], $input['clave_operacion']);
        });
    }

    /**
     * @param  array<string, string>  $rules
     * @return array<string, mixed>
     */
    private function input(Request $request, array $rules): array
    {
        $allowed = array_filter(array_keys($rules), fn (string $field): bool => ! str_contains($field, '.'));
        abort_if(array_diff(array_keys($request->all()), $allowed) !== [], 422, 'La operación contiene campos no admitidos.');

        return Validator::make($request->all(), $rules)->validate();
    }

    private function respond(callable $operation, bool $blocked = false): JsonResponse
    {
        try {
            abort_if(Gate::denies('viewAny', Certificate::class) || Gate::denies('viewAny', CertificateDocument::class),
                403, 'No autorizado para consultar documentos.');
            $data = $operation();
            $response = ($blocked || ($data['completada'] ?? null) === false)
                ? $this->information('La operación real requiere completar la infraestructura de firma.', 409, $data)
                : $this->ok($data);
        } catch (ValidationException $exception) {
            $response = $this->information('Datos de validación incorrectos.', 422, $exception->errors());
        } catch (HttpException $exception) {
            $response = $this->error($exception->getMessage(), $exception->getStatusCode());
        } catch (QueryException $exception) {
            $conflict = in_array(substr((string) $exception->getCode(), 0, 2), ['23', '40'], true);
            $response = $this->error('No se pudo completar la operación de firma.', $conflict ? 409 : 500);
        } catch (Throwable $exception) {
            Log::error('certificado.etapa_b.fallo', ['tipo' => $exception::class,
                'archivo' => $exception->getFile(), 'linea' => $exception->getLine()]);
            $response = $this->error('No se pudo completar la operación de firma.', 500);
        }

        return $response->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
