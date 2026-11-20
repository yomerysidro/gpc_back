<?php

namespace App\Http\Controllers\CertificateDocument;

use App\Data\CertificateDocument\CertificateDocumentData;
use App\Http\Controllers\Certification\CertificateHumanResourcesController;
use App\Http\Controllers\Controller;
use App\Models\Certificate\Certificate;
use App\Models\CertificateDocument\CertificateDocument;
use App\Services\CertificateDocumentGenerator;
use App\Services\CertificateDocumentWorkflow;
use App\Services\CertificateManagement;
use App\Services\CertificationReadiness;
use App\Services\PublicCertificateLookup;
use App\Traits\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class CertificateDocumentController extends Controller
{
    use ApiResponse;

    public function list()
    {
        if (request()->has('bandeja')) {
            return $this->developmentResponse(function (): Response {
                $filters = Validator::make(request()->query(), [
                    'bandeja' => 'required|in:por_firmar', 'page' => 'sometimes|integer|min:1',
                    'per_page' => 'sometimes|integer|min:1|max:100', 'id_actividad' => 'sometimes|integer|min:1',
                    'estado_flujo' => 'sometimes|in:PENDIENTE_FIRMA',
                ])->validate();

                return $this->ok(app(CertificateManagement::class)->pending(request()->user(), $filters), 'Documentos por firmar.')
                    ->header('Cache-Control', 'private, no-store');
            }, requireDevelopment: false);
        }

        if (Schema::hasColumn('certificado.certificado', 'id_actividad_sesion')) {
            return app(CertificateHumanResourcesController::class)->catalog(request());
        }

        return $this->respond('viewAny', fn () => CertificateDocumentData::list(request()->query()));
    }

    public function detail($id)
    {
        if (Gate::denies('viewAny', CertificateDocument::class)) {
            return $this->error('No autorizado.', 403);
        }
        if ($this->isManaged((int) $id) || request()->query('flujo') === 'firmas') {
            return $this->developmentResponse(fn (): Response => $this->ok(app(CertificateManagement::class)->detail((int) $id, request()->user()),
                'Documento administrativo.')->header('Cache-Control', 'private, no-store'), requireDevelopment: false);
        }

        return $this->respond('view', fn () => CertificateDocumentData::detail($id), $id);
    }

    public function store(Request $request)
    {
        return $this->respond('create', fn () => CertificateDocumentData::store($request->all()));
    }

    public function update(Request $request, $id)
    {
        return $this->respond('update', fn () => CertificateDocumentData::update($id, $request->all(), $request->isMethod('patch')), $id);
    }

    public function destroy($id)
    {
        return $this->respond('delete', fn () => CertificateDocumentData::destroy($id), $id);
    }

    public function generate(Request $request, int $id, CertificateDocumentGenerator $generator): Response
    {
        if (Gate::denies('create', CertificateDocument::class) || Gate::denies('viewAny', Certificate::class)) {
            return $this->error('No autorizado para generar documentos.', 403);
        }

        return $this->developmentResponse(function () use ($request, $id, $generator): Response {
            abort_if($request->all() !== [], 422, 'Esta operación recibe un objeto vacío; actor, código, archivo y hash los determina el servidor.');
            $data = $generator->generate($id, $request->user());

            return $this->ok($data, 'Documento de desarrollo guardado; no constituye emisión oficial.', $data['creado'] ? 201 : 200);
        });
    }

    public function download(Request $request, int $id, CertificateDocumentGenerator $generator): Response
    {
        if (Gate::denies('viewAny', CertificateDocument::class)) {
            return $this->error('No autorizado.', 403);
        }

        return $this->developmentResponse(function () use ($request, $id, $generator): Response {
            $document = DB::table('certificado.certificado_documento')->where('id_certificado_documento', $id)->first();
            abort_unless($document, 404, 'Documento no encontrado.');
            abort_if(Gate::denies('view', (new CertificateDocument)->newFromBuilder((array) $document)), 403, 'No autorizado.');
            if (($document->estado_flujo ?? null) !== null) {
                app(CertificateManagement::class)->document($id, $request->user());
            } elseif ($request->query('flujo') === 'firmas' || $this->isManaged($id)) {
                app(CertificateDocumentWorkflow::class)->document($id, $request->user());
            } else {
                app(CertificationReadiness::class)->assertDevelopmentClosureEnvironment();
                $generator->authorizeDocument($document, $request->user());
            }
            abort_unless($document->estado === 'Vigente', 409, 'El documento no está vigente.');
            $document = $generator->authorizedArtifact($document, readOnly: true);

            return $generator->privatePdfResponse($document, $request->boolean('descargar'));
        }, requireDevelopment: false);
    }

    public function history(Request $request, int $id, CertificateManagement $management): Response
    {
        return $this->developmentResponse(fn (): Response => $this->ok($management->history($id, $request->user()), 'Historial documental.')
            ->header('Cache-Control', 'private, no-store'), requireDevelopment: false);
    }

    public function reviewExisting(Request $request, int $id, CertificateDocumentWorkflow $workflow): Response
    {
        if (Gate::denies('viewAny', CertificateDocument::class)) {
            return $this->error('No autorizado.', 403);
        }

        return $this->developmentResponse(function () use ($request, $id, $workflow): Response {
            abort_if($request->all() !== [], 422, 'La revisión no acepta archivos, hash ni datos de composición del cliente.');

            return $this->ok($workflow->review($id, $request->user()), 'Revise el PDF registrado antes de remitirlo.')
                ->header('Cache-Control', 'private, no-store');
        }, requireDevelopment: false);
    }

    public function remitExisting(Request $request, int $id, CertificateDocumentWorkflow $workflow): Response
    {
        if (Gate::denies('viewAny', CertificateDocument::class)) {
            return $this->error('No autorizado.', 403);
        }

        return $this->developmentResponse(function () use ($request, $id, $workflow): Response {
            abort_if(array_diff(array_keys($request->all()), ['token_revision', 'confirmar']) !== [], 422, 'La remisión contiene campos no admitidos.');
            $input = Validator::make($request->all(), ['token_revision' => 'required|string|max:8192', 'confirmar' => 'required|accepted'])->validate();

            return $this->ok($workflow->remit($id, $input['token_revision'], $request->user()), 'Documento registrado remitido; pendiente de firma, sin firma criptográfica aplicada.')
                ->header('Cache-Control', 'private, no-store');
        }, requireDevelopment: false);
    }

    private function isManaged(int $id): bool
    {
        if (Schema::hasColumn('certificado.certificado_documento', 'estado_flujo')
            && DB::table('certificado.certificado_documento')->where('id_certificado_documento', $id)->whereNotNull('estado_flujo')->exists()) {
            return true;
        }

        return Schema::hasTable('certificado.certificado_documento') && Schema::hasTable('certificado.certificado')
            && DB::table('certificado.certificado_documento as d')->join('certificado.certificado as c', 'c.id_certificado', '=', 'd.id_certificado')
                ->where('d.id_certificado_documento', $id)->whereIn('c.estado', [CertificateManagement::PREPARED, CertificateManagement::PENDING])->exists();
    }

    public function verify(Request $request, string $code, CertificateDocumentGenerator $generator): Response
    {
        return $this->developmentResponse(function () use ($request, $code, $generator): Response {
            $document = $generator->documentByCode($code);
            $lookup = app(PublicCertificateLookup::class);
            $publicArtifact = $lookup->confirmedPublicArtifact($document);
            $verification = $generator->verification($document, $publicArtifact);
            if ($request->boolean('archivo')) {
                if (! $verification['puede_ver_documento']) {
                    Log::notice('certificado_documento.acceso_publico_denegado', [
                        'referencia' => hash_hmac('sha256', $code, (string) config('app.key')),
                    ]);
                    abort_if($lookup->isPublishable($document), 404, 'Documento no disponible.');
                    abort(403, 'El documento no está disponible para consulta pública.');
                }
                Log::info('certificado_documento.acceso_publico', [
                    'referencia' => hash_hmac('sha256', $code, (string) config('app.key')),
                    'descarga' => $request->boolean('descargar'),
                ]);

                if ($request->boolean('vista_firmas') && ! $request->boolean('descargar')) {
                    abort_unless($publicArtifact !== null, 404, 'Vista de firmas no disponible.');
                    $response = response($lookup->signaturePreview($publicArtifact, $generator), 200, [
                        'Content-Type' => 'application/pdf',
                        'Content-Disposition' => 'inline; filename="vista-certificado.pdf"',
                        'Cache-Control' => 'private, no-store',
                        'X-Content-Type-Options' => 'nosniff',
                    ]);
                } else {
                    $response = $generator->privatePdfResponse($publicArtifact ?? $document, $request->boolean('descargar'));
                }
                $response->headers->set('Referrer-Policy', 'no-referrer');
                $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

                return $response;
            }
            if ($request->acceptsHtml() && ! $request->expectsJson()) {
                return response()->view('certificates.verification', ['verification' => $verification])
                    ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer')
                    ->header('X-Content-Type-Options', 'nosniff');
            }

            return $this->ok($verification + ['vista_firmas_disponible' => $publicArtifact !== null], 'Consulta del registro y su archivo; no acredita firma ni emisión oficial.')
                ->header('Cache-Control', 'no-store');
        }, requireDevelopment: false);
    }

    private function developmentResponse(callable $operation, bool $requireDevelopment = true): Response
    {
        try {
            if ($requireDevelopment) {
                app(CertificationReadiness::class)->assertDevelopmentClosureEnvironment();
            }

            return $operation();
        } catch (ValidationException $exception) {
            return $this->information('Datos de validación incorrectos.', 422, $exception->errors());
        } catch (HttpException $exception) {
            return $this->error($exception->getMessage(), $exception->getStatusCode());
        } catch (QueryException $exception) {
            $conflict = in_array(substr((string) $exception->getCode(), 0, 2), ['23', '40'], true);

            return $this->error($conflict ? 'Conflicto de integridad; vuelva a consultar el documento.' : 'No se pudo completar la operación documental.', $conflict ? 409 : 500);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('No se pudo generar o consultar el documento.', 500);
        }
    }

    private function respond(string $ability, callable $operation, $id = null)
    {
        // Policies are provided by the application's existing authorization layer.
        // Absence of a policy denies access; authentication alone is insufficient.
        if (! request()->user()) {
            return $this->error('No autenticado.', 401);
        }
        if (in_array($ability, ['viewAny', 'create'], true)) {
            if (Gate::denies($ability, CertificateDocument::class)) {
                return $this->error('No autorizado.', 403);
            }
        } else {
            // Check general access before looking up personal or sensitive records.
            if (Gate::denies('viewAny', CertificateDocument::class)) {
                return $this->error('No autorizado.', 403);
            }
            if (! ctype_digit((string) $id) || (int) $id < 1) {
                return $this->error('Registro no encontrado.', 404);
            }
            $record = CertificateDocumentData::detail($id);
            if (! $record['success']) {
                return $this->error($record['message'], $record['status']);
            }
            $model = (new CertificateDocument)->newFromBuilder((array) $record['data']);
            if (Gate::denies($ability, $model)) {
                return $this->error('No autorizado.', 403);
            }
        }
        $response = $operation();
        if (! $response['success']) {
            return $this->information($response['message'], $response['status'], $response['data'] ?? []);
        }

        return $this->ok($response['data'], $response['message'], $response['status']);
    }
}
