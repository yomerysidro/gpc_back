<?php

namespace App\Services;

use App\Services\RequestSchema as Schema;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SessionSpeakers
{
    public static function supportsSessionLinks(): bool
    {
        return Schema::hasTable('certificado.actividad_ponente')
            && Schema::hasColumn('certificado.actividad_ponente', 'id_actividad_sesion')
            && Schema::hasTable('gestion.persona_ponente');
    }

    public static function assignments(): Builder
    {
        return DB::table('certificado.actividad_ponente as ap')
            ->join('gestion.persona_ponente as pp', 'pp.id_persona_ponente', '=', 'ap.id_persona_ponente');
    }

    public static function hasSessionAssignment(int $activityId, int $sessionId, int $personId): bool
    {
        return self::supportsSessionLinks() && self::assignments()
            ->where('ap.id_actividad', $activityId)->where('ap.id_actividad_sesion', $sessionId)
            ->where('pp.id_persona', $personId)->exists();
    }

    public function doubleRoleDocuments(?int $sessionId = null): Collection
    {
        if (! self::supportsSessionLinks()
            || ! Schema::hasColumn('certificado.certificado', 'id_actividad_sesion')
            || ! Schema::hasColumn('certificado.reconocimiento', 'id_actividad_ponente')
            || ! Schema::hasTable('certificado.certificado_documento')) {
            return collect();
        }

        return self::assignments()
            ->join('certificado.actividad_sesion as s', 's.id_actividad_sesion', '=', 'ap.id_actividad_sesion')
            ->whereColumn('s.id_actividad', 'ap.id_actividad')
            ->join('certificado.participacion as p', 'p.id_persona', '=', 'pp.id_persona')
            ->whereColumn('p.id_actividad', 'ap.id_actividad')
            ->join('certificado.certificado as cp', 'cp.id_participacion', '=', 'p.id_participacion')
            ->whereColumn('cp.id_persona', 'pp.id_persona')->whereColumn('cp.id_actividad_sesion', 's.id_actividad_sesion')
            ->join('certificado.reconocimiento as r', 'r.id_actividad_ponente', '=', 'ap.id_actividad_ponente')
            ->join('certificado.certificado as cr', 'cr.id_reconocimiento', '=', 'r.id_reconocimiento')
            ->whereColumn('cr.id_persona', 'pp.id_persona')
            ->join('certificado.certificado_documento as dp', 'dp.id_certificado', '=', 'cp.id_certificado')
            ->join('certificado.certificado_documento as dr', 'dr.id_certificado', '=', 'cr.id_certificado')
            ->whereColumn('dp.id_certificado_documento', '<>', 'dr.id_certificado_documento')
            ->when($sessionId !== null, fn (Builder $query) => $query->where('s.id_actividad_sesion', $sessionId))
            ->distinct()->orderBy('s.id_actividad_sesion')->orderBy('pp.id_persona')
            ->get(['s.id_actividad_sesion', 'pp.id_persona', 'dp.id_certificado_documento as id_documento_participacion',
                'dr.id_certificado_documento as id_documento_ponente']);
    }

    /** @return Collection<int, object> */
    public function assigned(int $activityId, int $sessionId): Collection
    {
        $this->assertSchema();

        $query = self::assignments()
            ->join('gestion.persona as p', 'p.id_persona', '=', 'pp.id_persona')
            ->where('ap.id_actividad', $activityId)->where('ap.id_actividad_sesion', $sessionId)
            ->orderBy('ap.id_actividad_ponente')
            ->select('ap.*', 'pp.id_persona', 'p.nombres', 'p.apellido_paterno', 'p.apellido_materno');
        if (Schema::hasTable('certificado.asistencia_ponente_sesion')) {
            $query->leftJoin('certificado.asistencia_ponente_sesion as aps', function ($join): void {
                $join->on('aps.id_actividad_sesion', '=', 'ap.id_actividad_sesion')
                    ->on('aps.id_persona', '=', 'pp.id_persona');
            })->addSelect('aps.estado as estado_asistencia');
        }

        return $query->get();
    }

    public function markAttendance(int $activity, int $session, int $assignment, string $state, int $actor): array
    {
        $this->assertSchema();
        abort_unless(Schema::hasTable('certificado.asistencia_ponente_sesion'), 409, 'El registro de asistencia de ponentes requiere la actualización de la base de datos.');

        return DB::transaction(function () use ($activity, $session, $assignment, $state, $actor): array {
            $current = $this->lockSession($activity, $session);
            abort_if($current->estado === CertificationReadiness::FINAL_SESSION_STATE, 409, 'La sesión está finalizada.');
            $person = self::assignments()->where('ap.id_actividad', $activity)
                ->where('ap.id_actividad_sesion', $session)->where('ap.id_actividad_ponente', $assignment)
                ->value('pp.id_persona');
            abort_unless($person, 404, 'Vínculo de ponente no encontrado en esta sesión.');
            DB::table('certificado.asistencia_ponente_sesion')->upsert([
                'id_actividad_sesion' => $session, 'id_persona' => $person, 'estado' => $state,
                'id_usuario_actualizacion' => $actor, 'actualizado_en' => now(),
            ], ['id_actividad_sesion', 'id_persona'], ['estado', 'id_usuario_actualizacion', 'actualizado_en']);

            return ['id_actividad_sesion' => $session, 'id_actividad_ponente' => $assignment, 'estado_asistencia' => $state];
        });
    }

    public function link(int $activityId, int $sessionId, int $speakerId): object
    {
        $this->assertSchema();

        return DB::transaction(function () use ($activityId, $sessionId, $speakerId): object {
            $this->lockSession($activityId, $sessionId);
            $speaker = DB::table('gestion.persona_ponente')->where('id_persona_ponente', $speakerId)->lockForUpdate()->first();
            abort_unless($speaker, 404, 'Ponente no encontrado.');
            $person = DB::table('gestion.persona')->where('id_persona', $speaker->id_persona)->lockForUpdate()->first();
            abort_unless($person, 409, 'El ponente no tiene una identidad real.');
            abort_if(DB::table('certificado.actividad_ponente as ap')
                ->join('gestion.persona_ponente as pp', 'pp.id_persona_ponente', '=', 'ap.id_persona_ponente')
                ->where('ap.id_actividad_sesion', $sessionId)->where('pp.id_persona', $speaker->id_persona)->exists(),
                409, 'La persona ya está vinculada a esta sesión.');
            $id = DB::table('certificado.actividad_ponente')->insertGetId([
                'id_actividad' => $activityId, 'id_actividad_sesion' => $sessionId, 'id_persona_ponente' => $speakerId,
            ], 'id_actividad_ponente');

            return DB::table('certificado.actividad_ponente')->where('id_actividad_ponente', $id)->first();
        });
    }

    public function unlink(int $activityId, int $sessionId, int $assignmentId): void
    {
        $this->assertSchema();
        DB::transaction(function () use ($activityId, $sessionId, $assignmentId): void {
            $this->lockSession($activityId, $sessionId);
            $assignment = DB::table('certificado.actividad_ponente')
                ->where('id_actividad_ponente', $assignmentId)->where('id_actividad', $activityId)
                ->where('id_actividad_sesion', $sessionId)->lockForUpdate()->first();
            abort_unless($assignment, 404, 'Vínculo de sesión no encontrado.');
            abort_if(DB::table('certificado.reconocimiento')->where('id_actividad_ponente', $assignmentId)->exists(),
                409, 'El vínculo sustenta un reconocimiento y debe conservarse.');
            DB::table('certificado.actividad_ponente')->where('id_actividad_ponente', $assignmentId)->delete();
        });
    }

    private function lockSession(int $activityId, int $sessionId): object
    {
        $activity = DB::table('certificado.actividad')->where('id_actividad', $activityId)->lockForUpdate()->first();
        abort_unless($activity, 404, 'Actividad no encontrada.');
        $session = DB::table('certificado.actividad_sesion')->where('id_actividad_sesion', $sessionId)->lockForUpdate()->first();
        abort_unless($session, 404, 'Sesión no encontrada.');
        abort_unless((int) $session->id_actividad === $activityId, 409, 'La sesión no pertenece a la actividad.');

        return $session;
    }

    public function assertSchema(): void
    {
        $request = request();
        $key = self::class.'.schema.'.spl_object_id(DB::connection());
        if ($request->route() !== null && $request->attributes->get($key) === true) {
            return;
        }
        abort_unless(Schema::hasColumn('certificado.actividad_ponente', 'id_actividad_sesion')
            && Schema::hasColumn('certificado.reconocimiento', 'id_actividad_ponente'),
            409, 'Se requiere aplicar previamente el SQL autorizado de ponentes por sesión.');
        if ($request->route() !== null) {
            $request->attributes->set($key, true);
        }
    }
}
