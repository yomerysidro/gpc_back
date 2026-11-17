<?php

namespace App\Http\Controllers\Certificate;

use App\Data\Certificate\CertificateData;
use App\Http\Controllers\Certification\CertificateHumanResourcesController;
use App\Http\Controllers\Controller;
use App\Models\Certificate\Certificate;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class CertificateController extends Controller
{
    use ApiResponse;

    public function list()
    {
        if (Schema::hasColumn('certificado.certificado', 'id_actividad_sesion')) {
            return app(CertificateHumanResourcesController::class)->catalog(request(), certificates: true);
        }

        return $this->respond('viewAny', fn () => CertificateData::list(request()->query()));
    }

    public function detail($id)
    {
        if (ctype_digit((string) $id) && Gate::allows('viewAny', Certificate::class)
            && Schema::hasColumn('certificado.certificado', 'id_actividad_sesion')
            && DB::table('certificado.certificado')->where('id_certificado', $id)->whereIn('estado', ['PREPARADO', 'PENDIENTE_FIRMA'])->exists()) {
            return app(CertificateHumanResourcesController::class)->preparedCertificate(request(), (int) $id);
        }

        return $this->respond('view', fn () => CertificateData::detail($id), $id);
    }

    public function store(Request $request)
    {
        return $this->respond('create', fn () => CertificateData::store($request->all()));
    }

    public function update(Request $request, $id)
    {
        return $this->respond('update', fn () => CertificateData::update($id, $request->all(), $request->isMethod('patch')), $id);
    }

    public function destroy($id)
    {
        return $this->respond('delete', fn () => CertificateData::destroy($id), $id);
    }

    private function respond(string $ability, callable $operation, $id = null)
    {
        // Policies are provided by the application's existing authorization layer.
        // Absence of a policy denies access; authentication alone is insufficient.
        if (! request()->user()) {
            return $this->error('No autenticado.', 401);
        }
        if (in_array($ability, ['viewAny', 'create'], true)) {
            if (Gate::denies($ability, Certificate::class)) {
                return $this->error('No autorizado.', 403);
            }
        } else {
            // Check general access before looking up personal or sensitive records.
            if (Gate::denies('viewAny', Certificate::class)) {
                return $this->error('No autorizado.', 403);
            }
            if (! ctype_digit((string) $id) || (int) $id < 1) {
                return $this->error('Registro no encontrado.', 404);
            }
            $record = CertificateData::detail($id);
            if (! $record['success']) {
                return $this->error($record['message'], $record['status']);
            }
            $model = (new Certificate)->newFromBuilder((array) $record['data']);
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
