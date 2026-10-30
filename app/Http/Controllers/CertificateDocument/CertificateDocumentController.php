<?php

namespace App\Http\Controllers\CertificateDocument;

use App\Data\CertificateDocument\CertificateDocumentData;
use App\Http\Controllers\Controller;
use App\Models\CertificateDocument\CertificateDocument;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CertificateDocumentController extends Controller
{
    use ApiResponse;

    public function list()
    {
        return $this->respond('viewAny', fn () => CertificateDocumentData::list(request()->query()));
    }

    public function detail($id)
    {
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
