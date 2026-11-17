<?php

namespace App\Services;

use App\Services\RequestSchema as Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SessionSpeakers
{
    /** @return Collection<int, object> */
    public function assigned(int $activityId, int $sessionId): Collection
    {
        $this->assertSchema();

        return DB::table('certificado.actividad_ponente as ap')
            ->join('gestion.persona_ponente as pp', 'pp.id_persona_ponente', '=', 'ap.id_persona_ponente')
            ->join('gestion.persona as p', 'p.id_persona', '=', 'pp.id_persona')
            ->where('ap.id_actividad', $activityId)->where('ap.id_actividad_sesion', $sessionId)
            ->orderBy('ap.id_actividad_ponente')
            ->get(['ap.*', 'pp.id_persona', 'p.nombres', 'p.apellido_paterno', 'p.apellido_materno']);
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

    private function lockSession(int $activityId, int $sessionId): void
    {
        $activity = DB::table('certificado.actividad')->where('id_actividad', $activityId)->lockForUpdate()->first();
        abort_unless($activity, 404, 'Actividad no encontrada.');
        $session = DB::table('certificado.actividad_sesion')->where('id_actividad_sesion', $sessionId)->lockForUpdate()->first();
        abort_unless($session, 404, 'Sesión no encontrada.');
        abort_unless((int) $session->id_actividad === $activityId, 409, 'La sesión no pertenece a la actividad.');
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
