<?php

namespace App\Http\Controllers\Certification;

use App\Http\Controllers\Controller;
use App\Services\AdministrativeSigners;
use App\Services\CertificateSigningStageB;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class AdministrativeSignerController extends Controller
{
    use ApiResponse;

    public function __construct(private AdministrativeSigners $signers) {}

    public function workers(Request $request): JsonResponse
    {
        return $this->listing($request, false);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->listing($request, true);
    }

    private function listing(Request $request, bool $authorizedOnly): JsonResponse
    {
        return $this->respond(function () use ($request, $authorizedOnly): array {
            $this->signers->authorize($request->user());
            abort_if(array_diff(array_keys($request->query()), ['id_area']) !== [], 422, 'Filtros no admitidos.');
            $input = Validator::make($request->query(), ['id_area' => 'sometimes|integer|min:1'])->validate();
            $areas = $this->signers->areas($request->user());
            if (isset($input['id_area'])) {
                $area = (int) $input['id_area'];
                abort_unless($areas === null || in_array($area, $areas, true), 404, 'Área no encontrada en su ámbito.');
                $areas = [$area];
            }

            return ['funcionarios' => $this->signers->workers($areas, authorizedOnly: $authorizedOnly),
                'origen' => 'relaciones_laborales_y_permisos_existentes', 'catalogo_independiente' => false,
                'registro_independiente_disponible' => false, 'requiere_pfx' => false, 'requiere_password' => false];
        });
    }

    public function store(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request): array {
            $this->signers->authorize($request->user());
            abort_if(array_diff(array_keys($request->all()), ['id_persona_trabajador', 'id_certificado_documento', 'orden_firma', 'modalidad', 'confirmar']) !== [], 422, 'Campos no admitidos; no se recibe PFX ni contraseña.');
            $input = Validator::make($request->all(), [
                'id_persona_trabajador' => 'required|integer|min:1', 'confirmar' => 'required|accepted',
                'id_certificado_documento' => 'sometimes|integer|min:1',
                'orden_firma' => 'required_with:id_certificado_documento|integer|min:1',
                'modalidad' => 'required_with:id_certificado_documento|in:PARALELA,SECUENCIAL',
            ])->validate();

            if (isset($input['id_certificado_documento'])) {
                return ['tipo_registro' => 'habilitacion_documental', 'catalogo_independiente' => false]
                    + app(CertificateSigningStageB::class)->assign((int) $input['id_certificado_documento'], $request->user(),
                        $input['modalidad'], [['id_persona_trabajador' => (int) $input['id_persona_trabajador'], 'orden_firma' => (int) $input['orden_firma']]]);
            }

            return $this->signers->enable((int) $input['id_persona_trabajador'], $request->user());
        });
    }

    private function respond(callable $operation): JsonResponse
    {
        try {
            $data = $operation();
            $response = ($data['completada'] ?? null) === false
                ? $this->information('No se guardó una habilitación administrativa.', 409, $data) : $this->ok($data);
        } catch (ValidationException $exception) {
            $response = $this->information('Datos de validación incorrectos.', 422, $exception->errors());
        } catch (HttpException $exception) {
            $response = $this->error($exception->getMessage(), $exception->getStatusCode());
        } catch (Throwable $exception) {
            Log::error('funcionarios.consulta_fallida', ['tipo' => $exception::class]);
            $response = $this->error('No se pudo comprobar la habilitación administrativa.', 500);
        }

        return $response->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
