<?php

namespace App\Http\Controllers\Certification;

use App\Http\Controllers\Controller;
use App\Services\CertificateDocumentGenerator;
use App\Services\CertificateHumanResources;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class CertificateHumanResourcesController extends Controller
{
    use ApiResponse;

    public function __construct(private CertificateHumanResources $service) {}

    public function send(Request $request, int $id): Response
    {
        return $this->respond(function () use ($request, $id): Response {
            $input = $this->input($request, ['confirmar' => 'required|accepted', 'id_area_destino' => 'required|integer|min:1',
                'revision_pdf' => 'required|string|regex:/^[a-f0-9]{64}$/D']);

            return $this->ok($this->service->send($id, (int) $input['id_area_destino'], $input['revision_pdf'], $request->user()));
        });
    }

    public function inbox(Request $request): Response
    {
        return $this->listing($request, false);
    }

    public function activities(Request $request): Response
    {
        return $this->respond(function () use ($request): Response {
            $input = $this->input($request, ['page' => 'sometimes|integer|min:1']);

            return $this->ok($this->service->activities($request->user(), (int) ($input['page'] ?? 1)));
        });
    }

    public function sessions(Request $request, int $activity): Response
    {
        return $this->respond(function () use ($request, $activity): Response {
            $input = $this->input($request, ['page' => 'sometimes|integer|min:1']);

            return $this->ok($this->service->sessions($request->user(), $activity, (int) ($input['page'] ?? 1)));
        });
    }

    public function certificates(Request $request, int $session): Response
    {
        return $this->respond(function () use ($request, $session): Response {
            $input = $this->input($request, ['id_persona' => 'sometimes|integer|min:1', 'page' => 'sometimes|integer|min:1']);

            return $this->ok($this->service->certificates($request->user(), $session, $input));
        });
    }

    public function detail(Request $request, int $id): Response
    {
        return $this->respond(fn (): Response => $this->ok($this->service->detail($id, $request->user())));
    }

    public function catalog(Request $request, bool $certificates = false): Response
    {
        return $this->respond(function () use ($request, $certificates): Response {
            $input = $this->input($request, ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100',
                'id_actividad' => 'sometimes|integer|min:1', 'id_sesion' => 'sometimes|integer|min:1',
                'id_persona' => 'sometimes|integer|min:1', 'id_certificado' => 'sometimes|integer|min:1',
                'id_tipo_certificado' => 'sometimes|integer|min:1', 'id_participacion' => 'sometimes|integer|min:1',
                'id_practica' => 'sometimes|integer|min:1', 'id_reconocimiento' => 'sometimes|integer|min:1',
                'codigo_verificacion' => 'sometimes|string|max:30', 'fecha_generacion' => 'sometimes|date_format:Y-m-d',
                'fecha_emision' => 'sometimes|nullable|date_format:Y-m-d', 'notificado' => 'sometimes|boolean',
                'fecha_notificacion' => 'sometimes|nullable|date', 'motivo_reemision' => 'sometimes|nullable|string|max:255',
                'estado' => $certificates ? 'sometimes|in:PREPARADO,PENDIENTE_FIRMA,FIRMADO,EMITIDO,ANULADO,PREPARADO_ENTREGA'
                    : 'sometimes|in:Vigente,Anulado,PREPARADO_ENTREGA',
                'estado_certificado' => 'sometimes|in:PREPARADO,PENDIENTE_FIRMA,FIRMADO,EMITIDO,ANULADO',
                'estado_preparacion' => 'sometimes|in:PREPARADO_ENTREGA']);

            return $this->ok($this->service->catalog($request->user(), $input, $certificates));
        });
    }

    public function preparedCertificate(Request $request, int $id): Response
    {
        return $this->respond(fn (): Response => $this->ok($this->service->preparedCertificate($id, $request->user())));
    }

    public function mine(Request $request): Response
    {
        return $this->listing($request, true);
    }

    private function listing(Request $request, bool $own): Response
    {
        return $this->respond(function () use ($request, $own): Response {
            $input = $this->input($request, ['id_actividad' => 'sometimes|integer|min:1', 'id_sesion' => 'sometimes|integer|min:1',
                ...($own ? [] : ['id_persona' => 'sometimes|integer|min:1']), 'page' => 'sometimes|integer|min:1']);

            return $this->ok($this->service->inbox($request->user(), $input, $own));
        });
    }

    public function pdf(Request $request, int $id): Response
    {
        return $this->file($request, $id, false);
    }

    public function ownPdf(Request $request, int $id): Response
    {
        return $this->file($request, $id, true);
    }

    private function file(Request $request, int $id, bool $own): Response
    {
        return $this->respond(function () use ($request, $id, $own): Response {
            $input = $this->input($request, ['descargar' => 'sometimes|boolean']);
            $document = $this->service->artifact($id, $request->user(), $own);

            return app(CertificateDocumentGenerator::class)->privatePdfResponse($document, (bool) ($input['descargar'] ?? false));
        });
    }

    public function receive(Request $request, int $id): Response
    {
        return $this->transition($request, $id, 'RECIBIDO_RRHH');
    }

    public function available(Request $request, int $id): Response
    {
        return $this->transition($request, $id, 'DISPONIBLE_ENTREGA');
    }

    public function deliver(Request $request, int $id): Response
    {
        return $this->transition($request, $id, 'ENTREGADO');
    }

    private function transition(Request $request, int $id, string $target): Response
    {
        return $this->respond(function () use ($request, $id, $target): Response {
            $this->input($request, ['confirmar' => 'required|accepted']);

            return $this->ok($this->service->transition($id, $request->user(), $target));
        });
    }

    public function history(Request $request, int $id): Response
    {
        return $this->respond(fn (): Response => $this->ok($this->service->history($id, $request->user())));
    }

    /** @param array<string, string> $rules
     * @return array<string, mixed>
     */
    private function input(Request $request, array $rules): array
    {
        abort_if(array_diff(array_keys($request->all()), array_keys($rules)) !== [], 422, 'La operación contiene campos no admitidos.');

        return $request->validate($rules);
    }

    private function respond(callable $operation): Response
    {
        try {
            $response = $operation();
        } catch (ValidationException $exception) {
            $response = $this->information('Datos de validación incorrectos.', 422, $exception->errors());
        } catch (HttpException $exception) {
            $response = $this->error($exception->getMessage(), $exception->getStatusCode());
        } catch (Throwable $exception) {
            report($exception);
            $response = $this->error('No se pudo completar la operación administrativa.', 500);
        }

        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
