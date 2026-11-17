<?php

namespace App\Http\Controllers\Signature;

use App\Data\Signature\SignatureData;
use App\Http\Controllers\Controller;
use App\Models\Signature\Signature;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SignatureController extends Controller
{
    use ApiResponse;

    public function list()
    {
        return $this->respond('viewAny', fn () => SignatureData::list(request()->query()));
    }

    public function detail($id)
    {
        return $this->respond('view', fn () => SignatureData::detail($id), $id);
    }

    public function store(Request $request)
    {
        return $this->respond('create', fn () => SignatureData::store($request->all()));
    }

    public function update(Request $request, $id)
    {
        return $this->respond('update', fn () => SignatureData::update($id, $request->all(), $request->isMethod('patch')), $id);
    }

    public function destroy($id)
    {
        return $this->respond('delete', fn () => SignatureData::destroy($id), $id);
    }

    private function respond(string $ability, callable $operation, $id = null)
    {
        // Policies are provided by the application's existing authorization layer.
        // Absence of a policy denies access; authentication alone is insufficient.
        if (! request()->user()) {
            return $this->error('No autenticado.', 401);
        }
        if (in_array($ability, ['viewAny', 'create'], true)) {
            if (Gate::denies($ability, Signature::class)) {
                return $this->error('No autorizado.', 403);
            }
        } else {
            // Check general access before looking up personal or sensitive records.
            if (Gate::denies('viewAny', Signature::class)) {
                return $this->error('No autorizado.', 403);
            }
            if (! ctype_digit((string) $id) || (int) $id < 1) {
                return $this->error('Registro no encontrado.', 404);
            }
            $record = SignatureData::detail($id);
            if (! $record['success']) {
                return $this->error($record['message'], $record['status']);
            }
            $model = (new Signature)->newFromBuilder((array) $record['data']);
            if (Gate::denies($ability, $model)) {
                return $this->error('No autorizado.', 403);
            }
        }
        if (in_array($ability, ['create', 'update', 'delete'], true)) {
            $ownerKeys = array_unique(array_filter([request()->input('id_persona_trabajador'), $record['data']->id_persona_trabajador ?? null], fn (mixed $value): bool => $value !== null));
            if ($ownerKeys === []) {
                return $this->information('Datos de identidad de firma requeridos.', 422, ['id_persona_trabajador' => ['Campo requerido.']]);
            }
            foreach ($ownerKeys as $ownerKey) {
                $ownsKey = DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', $ownerKey)->where('id_persona', request()->user()->id_persona)->exists();
                if (! $ownsKey) {
                    return $this->error('No puede utilizar ni modificar la firma de otro funcionario.', 403);
                }
            }
        }

        $response = $operation();
        if (! $response['success']) {
            return $this->information($response['message'], $response['status'], $response['data'] ?? []);
        }

        return $this->ok($response['data'], $response['message'], $response['status']);
    }
}
