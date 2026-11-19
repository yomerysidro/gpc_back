<?php

namespace App\Services;

use App\Http\Data\Attendance\AttendanceData;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class SessionParticipants
{
    /** @return array<string, mixed> */
    public function listing(object $session, bool $candidates): array
    {
        $query = DB::table('certificado.participacion as p')->join('gestion.persona as persona', 'persona.id_persona', '=', 'p.id_persona')
            ->leftJoin('certificado.asistencia as a', function (JoinClause $join) use ($session): void {
                $join->on('a.id_participacion', '=', 'p.id_participacion')->where('a.id_actividad_sesion', $session->id_actividad_sesion);
            })->where('p.id_actividad', $session->id_actividad);
        if (SessionSpeakers::supportsSessionLinks()) {
            $query->whereNotExists(SessionSpeakers::assignments()->selectRaw('1')
                ->where('ap.id_actividad_sesion', $session->id_actividad_sesion)
                ->where('ap.id_actividad', $session->id_actividad)->whereColumn('pp.id_persona', 'p.id_persona'));
        }
        $active = app(CertificationReadiness::class)->activeAttendanceEnrollmentStates();
        if ($active !== []) {
            $query->whereIn(DB::raw('upper(trim(cast(p.estado as text)))'), $active);
        }
        if (! $candidates) {
            $query->whereNotNull('a.id_asistencia');
        }

        return ['id_actividad' => (int) $session->id_actividad, 'id_actividad_sesion' => (int) $session->id_actividad_sesion,
            'seleccion_persistida_en' => 'asistencia', 'estado_inicial' => 'AUSENTE',
            'participantes' => $query->orderBy('p.id_participacion')->get(['p.id_participacion', 'p.id_persona', 'p.fecha_inscripcion', 'p.estado as estado_inscripcion',
                'persona.nombres', 'persona.apellido_paterno', 'persona.apellido_materno', 'a.id_asistencia', 'a.estado as estado_asistencia',
                'a.hora_entrada', 'a.hora_salida'])->map(fn (object $row): array => [
                    'id_participacion' => (int) $row->id_participacion, 'id_persona' => (int) $row->id_persona,
                    'nombre_completo' => trim(implode(' ', array_filter([$row->nombres, $row->apellido_paterno, $row->apellido_materno]))),
                    'fecha_inscripcion' => $row->fecha_inscripcion, 'estado_inscripcion' => $row->estado_inscripcion,
                    'seleccionado' => $row->id_asistencia !== null,
                    'id_asistencia' => $row->id_asistencia === null ? null : (int) $row->id_asistencia,
                    'estado_asistencia' => $row->estado_asistencia, 'hora_entrada' => $row->hora_entrada, 'hora_salida' => $row->hora_salida,
                ])->all()];
    }

    /** @param list<int> $participations
     * @return array<string, mixed>
     */
    public function select(object $session, array $participations): array
    {
        return DB::transaction(function () use ($session, $participations): array {
            $enrollments = DB::table('certificado.participacion')->whereIn('id_participacion', $participations)
                ->where('id_actividad', $session->id_actividad)->orderBy('id_participacion')->lockForUpdate()->get();
            abort_unless($enrollments->count() === count($participations), 422, 'Todos los participantes deben estar inscritos en esta actividad.');
            $current = DB::table('certificado.actividad_sesion')->where('id_actividad_sesion', $session->id_actividad_sesion)->lockForUpdate()->first();
            abort_unless($current && (int) $current->id_actividad === (int) $session->id_actividad, 409, 'La sesión cambió; vuelva a consultarla.');
            abort_if($current->estado === CertificationReadiness::FINAL_SESSION_STATE, 409, 'La sesión está finalizada.');
            if (SessionSpeakers::supportsSessionLinks()) {
                abort_if(SessionSpeakers::assignments()->where('ap.id_actividad_sesion', $current->id_actividad_sesion)
                    ->where('ap.id_actividad', $current->id_actividad)
                    ->whereIn('pp.id_persona', $enrollments->pluck('id_persona'))->exists(),
                    409, 'La asistencia de los ponentes se registra en su propia sección.');
            }
            $active = app(CertificationReadiness::class)->activeAttendanceEnrollmentStates();
            abort_if($active !== [] && $enrollments->contains(fn (object $row): bool => ! in_array(strtoupper(trim($row->estado ?? '')), $active, true)),
                422, 'Todos los participantes deben tener una inscripción vigente.');
            foreach ($enrollments as $enrollment) {
                if (DB::table('certificado.asistencia')->where('id_participacion', $enrollment->id_participacion)
                    ->where('id_actividad_sesion', $current->id_actividad_sesion)->exists()) {
                    continue;
                }
                $result = AttendanceData::createAttendance(['ID_PARTICIPACION' => (int) $enrollment->id_participacion,
                    'ID_ACTIVIDAD_SESION' => (int) $current->id_actividad_sesion, 'ESTADO' => 'AUSENTE']);
                abort_unless($result['success'], $result['status'] ?? 500, $result['message']);
            }

            return $this->listing($current, false);
        });
    }

    /** @return array<string, mixed> */
    public function remove(object $session, int $participation): array
    {
        return DB::transaction(function () use ($session, $participation): array {
            DB::table('certificado.participacion')->where('id_participacion', $participation)->lockForUpdate()->first();
            $id = DB::table('certificado.asistencia')->where('id_participacion', $participation)
                ->where('id_actividad_sesion', $session->id_actividad_sesion)->value('id_asistencia');
            abort_unless($id, 404, 'Participante no seleccionado en esta sesión.');
            $result = AttendanceData::deleteAttendance((int) $id);
            abort_unless($result['success'], $result['status'] ?? 500, $result['message']);

            return ['retirado' => true, 'id_participacion' => $participation, 'id_actividad_sesion' => (int) $session->id_actividad_sesion];
        });
    }
}
