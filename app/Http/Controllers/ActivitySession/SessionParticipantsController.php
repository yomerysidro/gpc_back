<?php

namespace App\Http\Controllers\ActivitySession;

use App\Http\Controllers\Controller;
use App\Http\Data\ActivityRequests\ActivityRequestData;
use App\Models\ActivitySession\ActivitySession;
use App\Models\Attendance\Attendance;
use App\Models\Participation\Participation;
use App\Services\SessionParticipants;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class SessionParticipantsController extends Controller
{
    use ApiResponse;

    public function __construct(private SessionParticipants $participants) {}

    public function candidates(Request $request, int $id): JsonResponse
    {
        return $this->respond(fn (): array => $this->participants->listing($this->session($request, $id), true));
    }

    public function selected(Request $request, int $id): JsonResponse
    {
        return $this->respond(fn (): array => $this->participants->listing($this->session($request, $id), false));
    }

    public function select(Request $request, int $id): JsonResponse
    {
        return $this->respond(function () use ($request, $id): array {
            $session = $this->session($request, $id, 'create');
            abort_if(array_diff(array_keys($request->all()), ['ids_participacion']) !== [], 422, 'La selección contiene campos no admitidos.');
            $input = $request->validate(['ids_participacion' => 'required|array|min:1|max:500', 'ids_participacion.*' => 'required|integer|min:1|distinct']);

            return $this->participants->select($session, array_map('intval', $input['ids_participacion']));
        });
    }

    public function remove(Request $request, int $id, int $participation): JsonResponse
    {
        return $this->respond(function () use ($request, $id, $participation): array {
            $session = $this->session($request, $id);
            $attendance = DB::table('certificado.asistencia')->where('id_actividad_sesion', $id)->where('id_participacion', $participation)->first();
            abort_unless($attendance, 404, 'Participante no seleccionado en esta sesión.');
            abort_unless(Gate::forUser($request->user())->allows('delete', (new Attendance)->newFromBuilder((array) $attendance)),
                403, 'No autorizado para retirar participantes.');

            return $this->participants->remove($session, $participation);
        });
    }

    private function session(Request $request, int $id, string $ability = 'viewAny'): object
    {
        $actor = $request->user();
        abort_unless(Gate::forUser($actor)->allows('viewAny', ActivitySession::class)
            && Gate::forUser($actor)->allows('viewAny', Participation::class)
            && Gate::forUser($actor)->allows('viewAny', Attendance::class), 403, 'No autorizado para consultar participantes de sesión.');
        abort_unless(Gate::forUser($actor)->allows($ability, Attendance::class), 403, 'No autorizado para modificar la selección.');
        $session = DB::table('certificado.actividad_sesion as s')->join('certificado.actividad as a', 'a.id_actividad', '=', 's.id_actividad')
            ->where('s.id_actividad_sesion', $id)->select('s.*', 'a.id_area')->first();
        abort_unless($session, 404, 'Sesión no encontrada.');
        abort_unless(Gate::forUser($actor)->allows('view', (new ActivitySession)->newFromBuilder((array) $session)), 403, 'No autorizado.');
        if (! $actor->isSystemAdministrator()) {
            $areas = ActivityRequestData::authorizedAreas((int) $actor->id_persona);
            abort_unless($areas['success'], $areas['status'] ?? 500, 'No se pudo verificar el ámbito.');
            abort_unless($areas['data']->contains('id_area', $session->id_area), 404, 'Sesión no encontrada en su ámbito.');
        }

        return $session;
    }

    private function respond(callable $operation): JsonResponse
    {
        try {
            $response = $this->ok($operation());
        } catch (ValidationException $exception) {
            $response = $this->information('Datos de validación incorrectos.', 422, $exception->errors());
        } catch (HttpException $exception) {
            $response = $this->error($exception->getMessage(), $exception->getStatusCode());
        } catch (Throwable $exception) {
            report($exception);
            $response = $this->error('No se pudo gestionar los participantes de la sesión.', 500);
        }

        return $response->header('Cache-Control', 'private, no-store');
    }
}
