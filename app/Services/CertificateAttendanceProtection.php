<?php

namespace App\Services;

use App\Services\RequestSchema as Schema;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class CertificateAttendanceProtection
{
    public static function forAttendance(int $participation, int $session): bool
    {
        $query = DB::table('certificado.certificado')->where('id_participacion', $participation);
        if (Schema::hasColumn('certificado.certificado', 'id_actividad_sesion')) {
            $query->where(fn (Builder $scope) => $scope->whereNull('id_actividad_sesion')->orWhere('id_actividad_sesion', $session));
        }

        return $query->lockForUpdate()->exists();
    }

    public static function forSession(int $session): bool
    {
        if (Schema::hasColumn('certificado.reconocimiento', 'id_actividad_ponente')
            && DB::table('certificado.reconocimiento as r')
                ->join('certificado.actividad_ponente as ap', 'ap.id_actividad_ponente', '=', 'r.id_actividad_ponente')
                ->where('ap.id_actividad_sesion', $session)->exists()) {
            return true;
        }
        $query = DB::table('certificado.certificado')->whereIn('id_participacion',
            DB::table('certificado.asistencia')->select('id_participacion')->where('id_actividad_sesion', $session));
        if (Schema::hasColumn('certificado.certificado', 'id_actividad_sesion')) {
            $query = DB::table('certificado.certificado')->where(fn (Builder $scope) => $scope
                ->where('id_actividad_sesion', $session)->orWhere(fn (Builder $legacy) => $legacy
                ->whereNull('id_actividad_sesion')->whereIn('id_participacion',
                    DB::table('certificado.asistencia')->select('id_participacion')->where('id_actividad_sesion', $session))));
        }

        return $query->lockForUpdate()->exists();
    }
}
