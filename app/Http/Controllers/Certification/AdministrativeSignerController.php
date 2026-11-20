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
        return $this->listing($request);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->listing($request);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->respond(fn (): array => $this->signers->detail($id, $request->user()));
    }

    public function enable(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request): array {
            $actor = $request->user();
            abort_unless($actor->estado && in_array('ponentes-firmantes.editar', $actor->permissionKeys(), true), 403,
                'Se requiere permiso para administrar habilitaciones de firmantes.');
            abort_if(array_diff(array_keys($request->all()), ['id_persona_trabajador', 'confirmar', 'imagen_firma']) !== [], 422, 'Campos no admitidos.');
            $input = Validator::make($request->all(), ['id_persona_trabajador' => 'required|integer|min:1',
                'confirmar' => 'sometimes|accepted',
                'imagen_firma' => ['sometimes', 'required', 'file', 'mimetypes:image/png,image/jpeg', 'max:1024', 'dimensions:min_width=1,min_height=1,max_width=1600,max_height=800'],
            ])->validate();

            return $this->signers->changeAuthorization((int) $input['id_persona_trabajador'], $actor, image: $input['imagen_firma'] ?? null);
        });
    }

    public function disable(Request $request, int $id): JsonResponse
    {
        return $this->respond(fn (): array => $this->signers->changeAuthorization($id, $request->user(), false));
    }

    private function listing(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request): array {
            $this->signers->authorize($request->user());
            abort_if(array_diff(array_keys($request->query()), ['id_area']) !== [], 422, 'Filtros no admitidos.');
            $input = Validator::make($request->query(), ['id_area' => 'sometimes|integer|min:1'])->validate();
            $areas = $this->signers->areas($request->user());
            if (isset($input['id_area'])) {
                $area = (int) $input['id_area'];
                abort_unless($areas === null || in_array($area, $areas, true), 404, 'Área no encontrada en su ámbito.');
                $areas = [$area];
            }

            return ['funcionarios' => $this->signers->workers($areas, includeInactive: true),
                'origen' => 'trabajadores_municipales', 'catalogo_independiente' => false,
                'registro_independiente_disponible' => $this->signers->authorizationAvailability()['disponible'], 'requiere_pfx' => false, 'requiere_password' => false,
                'habilitacion_administrativa' => $this->signers->authorizationAvailability()];
        });
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->exists('id_certificado_documento')) {
            return $this->enable($request);
        }

        return $this->respond(function () use ($request): array {
            $this->signers->authorize($request->user());
            abort_if(array_diff(array_keys($request->all()), ['id_persona_trabajador', 'id_certificado_documento', 'orden_firma', 'modalidad', 'confirmar']) !== [], 422, 'Campos no admitidos; no se recibe PFX ni contraseña.');
            $input = Validator::make($request->all(), [
                'id_persona_trabajador' => 'required|integer|min:1', 'confirmar' => 'required|accepted',
                'id_certificado_documento' => 'required|integer|min:1',
                'orden_firma' => 'required|integer|min:1',
                'modalidad' => 'required|in:PARALELA,SECUENCIAL',
            ])->validate();

            return ['tipo_registro' => 'asignacion_documental', 'catalogo_independiente' => false]
                + app(CertificateSigningStageB::class)->assign((int) $input['id_certificado_documento'], $request->user(),
                    $input['modalidad'], [['id_persona_trabajador' => (int) $input['id_persona_trabajador'], 'orden_firma' => (int) $input['orden_firma']]]);
        });
    }

    private function respond(callable $operation): JsonResponse
    {
        try {
            $data = $operation();
            $response = ($data['completada'] ?? null) === false
                ? $this->information('No se guardaron cambios.', 409, $data) : $this->ok($data);
        } catch (ValidationException $exception) {
            $response = $this->information('Datos de validación incorrectos.', 422, $exception->errors());
        } catch (HttpException $exception) {
            $response = $this->error($exception->getMessage(), $exception->getStatusCode());
        } catch (Throwable $exception) {
            Log::error('funcionarios.consulta_fallida', ['tipo' => $exception::class]);
            $response = $this->error('No se pudo consultar trabajadores o guardar la asignación documental.', 500);
        }

        return $response->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
