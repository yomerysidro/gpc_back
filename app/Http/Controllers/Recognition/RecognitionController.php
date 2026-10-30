<?php

namespace App\Http\Controllers\Recognition;

use App\Data\Recognition\RecognitionData;
use App\Http\Controllers\Controller;
use App\Models\Recognition\Recognition;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RecognitionController extends Controller
{
    use ApiResponse;

    public function list()
    {
        return $this->respond('viewAny', fn () => RecognitionData::list(request()->query()));
    }

    public function detail($id)
    {
        return $this->respond('view', fn () => RecognitionData::detail($id), $id);
    }

    public function store(Request $request)
    {
        return $this->respond('create', fn () => RecognitionData::store($request->all()));
    }

    public function update(Request $request, $id)
    {
        return $this->respond('update', fn () => RecognitionData::update($id, $request->all(), $request->isMethod('patch')), $id);
    }

    public function destroy($id)
    {
        return $this->respond('delete', fn () => RecognitionData::destroy($id), $id);
    }

    private function respond(string $ability, callable $operation, $id = null)
    {
        // Policies are provided by the application's existing authorization layer.
        // Absence of a policy denies access; authentication alone is insufficient.
        if (! request()->user()) {
            return $this->error('No autenticado.', 401);
        }
        if (in_array($ability, ['viewAny', 'create'], true)) {
            if (Gate::denies($ability, Recognition::class)) {
                return $this->error('No autorizado.', 403);
            }
        } else {
            // Check general access before looking up personal or sensitive records.
            if (Gate::denies('viewAny', Recognition::class)) {
                return $this->error('No autorizado.', 403);
            }
            if (! ctype_digit((string) $id) || (int) $id < 1) {
                return $this->error('Registro no encontrado.', 404);
            }
            $record = RecognitionData::detail($id);
            if (! $record['success']) {
                return $this->error($record['message'], $record['status']);
            }
            $model = (new Recognition)->newFromBuilder((array) $record['data']);
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
