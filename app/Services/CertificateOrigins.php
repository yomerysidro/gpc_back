<?php

namespace App\Services;

use App\Http\Data\ActivityRequests\ActivityRequestData;
use App\Models\Activity\Activity;
use App\Models\ActivitySession\ActivitySession;
use App\Models\ActivitySpeaker\ActivitySpeaker;
use App\Models\Practice\Practice;
use App\Models\Recognition\Recognition;
use App\Models\User\User;
use App\Services\RequestSchema as Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CertificateOrigins
{
    public function assignment(int $id): ?ActivitySpeaker
    {
        return RequestReadMemo::remember(ActivitySpeaker::class.'.'.$id, fn (): ?ActivitySpeaker => ActivitySpeaker::find($id));
    }

    private function activity(int $id): ?Activity
    {
        return RequestReadMemo::remember(Activity::class.'.'.$id, fn (): ?Activity => Activity::find($id));
    }

    public function authorize(string $origin, int $id, User $actor): void
    {
        if (in_array($origin, ['ponente_actividad', 'ponente_sesion'], true)) {
            abort_unless(Gate::forUser($actor)->allows('viewAny', ActivitySpeaker::class)
                && Gate::forUser($actor)->allows('viewAny', Activity::class), 403, 'No autorizado para consultar ponentes.');
            $assignment = $this->assignment($id);
            abort_unless($assignment, 404, 'Asignación de ponente no encontrada.');
            $activity = $this->activity($assignment->id_actividad);
            abort_unless($activity, 409, 'La asignación no tiene una actividad válida.');
            abort_unless(Gate::forUser($actor)->allows('view', $assignment) && Gate::forUser($actor)->allows('view', $activity),
                403, 'No autorizado para esta actividad.');
            $area = $activity->id_area;
        } else {
            abort_unless(in_array($origin, ['reconocimiento', 'practica'], true), 422, 'Origen no admitido.');
            $model = $origin === 'reconocimiento' ? Recognition::class : Practice::class;
            abort_unless(Gate::forUser($actor)->allows('viewAny', $model), 403, 'No autorizado para consultar el origen.');
            $record = $model::find($id);
            abort_unless($record, 404, 'Origen no encontrado.');
            abort_unless(Gate::forUser($actor)->allows('view', $record), 403, 'No autorizado para consultar este origen.');
            $area = $origin === 'reconocimiento' ? $record->worker?->id_area : $record->id_area;
        }
        abort_unless($area !== null, 409, 'El origen no tiene un área coherente.');
        if (! $actor->isSystemAdministrator()) {
            $areas = ActivityRequestData::authorizedAreas((int) $actor->id_persona);
            abort_unless($areas['success'], $areas['status'] ?? 500, 'No se pudo comprobar el ámbito.');
            abort_unless($areas['data']->contains('id_area', (int) $area), 403, 'Origen fuera del ámbito autorizado.');
        }
    }

    public function lock(string $origin, int $id): void
    {
        if (in_array($origin, ['ponente_actividad', 'ponente_sesion'], true)) {
            $assignment = DB::table('certificado.actividad_ponente')->where('id_actividad_ponente', $id)->first();
            abort_unless($assignment, 404, 'Asignación de ponente no encontrada.');
            DB::table('certificado.actividad')->where('id_actividad', $assignment->id_actividad)->lockForUpdate()->first();
            DB::table('certificado.actividad_ponente')->where('id_actividad', $assignment->id_actividad)->lockForUpdate()->get();
            $speaker = DB::table('gestion.persona_ponente')->where('id_persona_ponente', $assignment->id_persona_ponente)->lockForUpdate()->first();
            abort_unless($speaker, 409, 'La asignación perdió su identidad de ponente.');
            DB::table('gestion.persona')->where('id_persona', $speaker->id_persona)->lockForUpdate()->first();
            DB::table('gestion.persona_trabajador')->where('id_persona', $speaker->id_persona)->lockForUpdate()->get();
            DB::table('certificado.solicitud_actividad')->where('id_actividad', $assignment->id_actividad)->lockForUpdate()->get();

            return;
        }
        $table = $origin === 'reconocimiento' ? 'certificado.reconocimiento' : 'gestion.practica';
        $record = DB::table($table)->where('id_'.$origin, $id)->lockForUpdate()->first();
        abort_unless($record, 404, 'Origen no encontrado.');
        if ($origin === 'reconocimiento' && ($record->id_actividad_ponente ?? null) !== null) {
            $assignment = DB::table('certificado.actividad_ponente')->where('id_actividad_ponente', $record->id_actividad_ponente)->first();
            abort_unless($assignment, 409, 'El reconocimiento perdió su asignación de ponente.');
            $this->lock($assignment->id_actividad_sesion === null ? 'ponente_actividad' : 'ponente_sesion', (int) $record->id_actividad_ponente);

            return;
        }
        if ($origin === 'reconocimiento') {
            $person = DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', $record->id_persona_trabajador)->lockForUpdate()->first();
        } else {
            $person = DB::table('gestion.persona_practicante')->where('id_persona_practicante', $record->id_persona_practicante)->lockForUpdate()->first();
            $supervisors = DB::table('gestion.practica_supervisor')->where('id_practica', $id)->lockForUpdate()->get();
            DB::table('gestion.persona_trabajador')->whereIn('id_persona_trabajador', $supervisors->pluck('id_persona_trabajador'))->lockForUpdate()->get();
            DB::table('gestion.institucion')->where('id_institucion', $record->id_institucion)->lockForUpdate()->first();
            DB::table('gestion.area')->where('id_area', $record->id_area)->lockForUpdate()->first();
        }
        abort_unless($person, 409, 'El origen perdió la identidad del beneficiario.');
        DB::table('gestion.persona')->where('id_persona', $person->id_persona)->lockForUpdate()->first();
    }

    public function message(object $context): string
    {
        if ($context->origen === 'ponente_sesion' && isset($context->texto_reconocimiento)) {
            return $context->texto_reconocimiento['mensaje'];
        }
        $record = $context->datos_origen;
        if (in_array($context->origen, ['ponente_actividad', 'ponente_sesion'], true)) {
            return 'En reconocimiento a su participación como ponente en la actividad '.$context->actividad
                .($context->origen === 'ponente_sesion' ? ', sesión '.$context->sesion.' del '.$context->fecha_sesion : '').'.';
        }

        return $context->origen === 'reconocimiento'
            ? trim($record['titulo']).'. '.trim($record['motivo'])
            : 'Por haber realizado sus prácticas en '.$record['area'].', del '.$record['fecha_inicio'].' al '.$record['fecha_fin'].'.';
    }

    /** @param array{mensaje: string, motivo: string}|null $text */
    public function withSessionRecognitionText(object $context, ?array $text): object
    {
        if ($text !== null) {
            abort_unless($context->origen === 'ponente_sesion', 422, 'La edición de texto sólo corresponde a ponentes por sesión.');
            $context->texto_reconocimiento = ['v' => 1, 'mensaje' => $text['mensaje'], 'motivo' => $text['motivo']];
        }

        return $context;
    }

    public function recognitionMotive(object $context): string
    {
        return $context->origen === 'ponente_sesion' && isset($context->texto_reconocimiento)
            ? $context->texto_reconocimiento['motivo'] : $this->message($context);
    }

    /** @param array{message: string, context: array<string, mixed>} $approved
     * @return array<string, mixed>
     */
    public function savedSessionRecognitionText(array $approved): array
    {
        if (($approved['context']['origen'] ?? null) !== 'ponente_sesion') {
            return [];
        }

        return ['mensaje' => $approved['message'], 'mensaje_aprobado' => $approved['message'],
            'motivo' => $approved['context']['texto_reconocimiento']['motivo'] ?? $approved['message'],
            'texto_editable' => false, 'mensaje_persistido_en_pdf' => true, 'mensaje_estructurado_disponible' => true];
    }

    public function documentContext(object $document): object
    {
        $approved = app(CertificateDocumentVersions::class)->approvedContent($document);
        if (in_array($approved['context']['origen'] ?? null, ['ponente_actividad', 'ponente_sesion'], true)) {
            $context = $this->context($approved['context']['origen'], (int) $approved['context']['id_origen']);
            if (isset($approved['context']['texto_reconocimiento'])) {
                $text = $approved['context']['texto_reconocimiento'];
                abort_unless(is_array($text) && ($text['v'] ?? null) === 1 && is_string($text['mensaje'] ?? null)
                    && is_string($text['motivo'] ?? null) && $text['mensaje'] === $approved['message'], 409, 'El texto aprobado no conserva su identidad documental.');
                $context = $this->withSessionRecognitionText($context, $text);
            }
            $recognition = DB::table('certificado.reconocimiento')->where('id_reconocimiento', $document->id_reconocimiento)->first();
            abort_unless($recognition && $document->id_participacion === null && $document->id_practica === null
                && ($document->id_sesion ?? null) === null && (int) $document->id_persona === $context->id_persona
                && (int) $document->id_tipo_certificado === $context->id_tipo_certificado
                && ($recognition->id_persona_trabajador === null ? null : (int) $recognition->id_persona_trabajador) === $context->datos_origen['id_persona_trabajador']
                && ($context->origen !== 'ponente_sesion' || ((int) $recognition->id_actividad_ponente === $context->id_actividad_ponente
                    && (! Schema::hasColumn('certificado.reconocimiento', 'id_tipo_certificado')
                        || (int) $recognition->id_tipo_certificado === $context->id_tipo_certificado)))
                && $recognition->titulo === $context->datos_origen['titulo']
                && $recognition->motivo === $this->recognitionMotive($context) && $recognition->estado === CertificateManagement::PREPARED
                && (array) $context === $approved['context'], 409, 'El reconocimiento no conserva el ponente y la actividad acreditados.');

            return $context;
        }
        $origin = $document->id_reconocimiento !== null ? 'reconocimiento' : 'practica';
        $id = (int) ($origin === 'reconocimiento' ? $document->id_reconocimiento : $document->id_practica);
        $context = $this->context($origin, $id);
        abort_unless(($document->id_reconocimiento !== null) !== ($document->id_practica !== null)
            && $document->id_participacion === null && ($document->id_sesion ?? null) === null
            && (int) $context->id_persona === (int) $document->id_persona
            && (int) $context->id_tipo_certificado === (int) $document->id_tipo_certificado,
            409, 'El documento no corresponde al origen y al beneficiario acreditados.');
        $approved = app(CertificateDocumentVersions::class)->approvedContent($document);
        abort_unless((array) $context === $approved['context'], 409, 'El origen acreditado cambió respecto del PDF preparado.');

        return $context;
    }

    /** @return array<string, mixed> */
    public function recognition(int $id): array
    {
        $recognition = DB::table('certificado.reconocimiento as r')
            ->join('gestion.persona_trabajador as t', 't.id_persona_trabajador', '=', 'r.id_persona_trabajador')
            ->join('gestion.persona as p', 'p.id_persona', '=', 't.id_persona')
            ->where('r.id_reconocimiento', $id)
            ->first(['r.*', 't.id_persona', 't.id_area', 'p.nombres', 'p.apellido_paterno', 'p.apellido_materno']);
        abort_unless($recognition, 409, 'El reconocimiento no tiene un trabajador y una persona coherentes.');
        $blockers = $this->policyBlockers('reconocimiento', $recognition->estado);
        if (($recognition->id_actividad_ponente ?? null) !== null) {
            $blockers[] = 'Este reconocimiento pertenece a un vínculo de sesión; utilice el origen ponente_sesion.';
        }
        if (trim($recognition->titulo ?? '') === '' || trim($recognition->motivo ?? '') === ''
            || $recognition->fecha_reconocimiento === null || $recognition->fecha_reconocimiento > today()->toDateString()) {
            $blockers[] = 'El reconocimiento requiere título, motivo y fecha real no futura.';
        }

        return $this->evaluation('reconocimiento', $id, [
            'reconocimiento' => $recognition, 'id_persona' => (int) $recognition->id_persona,
            'id_area' => (int) $recognition->id_area, 'id_actividad' => null, 'id_sesion' => null,
            'ponente_externo_admitido' => false,
        ], $blockers);
    }

    /** @return list<string> */
    private function policyBlockers(string $origin, string $state): array
    {
        $policy = config('certification.origins.'.$origin, []);
        if (! is_string($policy['reference'] ?? null) || trim($policy['reference']) === ''
            || ! is_array($policy['eligible_states'] ?? null) || $policy['eligible_states'] === []) {
            return ['Falta definir la política institucional que acredita autorización y requisitos mediante el estado registrado; no se infiere del texto libre.'];
        }

        return in_array($state, $policy['eligible_states'], true) ? [] : ['El estado registrado no acredita la aprobación requerida por la política institucional.'];
    }

    /** @param array<string, mixed> $data
     * @param  list<string>  $blockers
     * @return array<string, mixed>
     */
    private function evaluation(string $origin, int $id, array $data, array $blockers): array
    {
        $types = DB::table('certificado.tipo_certificado')->where('estado', true)
            ->where('nombre_tipo', config('certification.origins.'.$origin.'.type_name'))->get();
        if ($types->count() !== 1) {
            $blockers[] = 'No existe un tipo documental vigente e inequívoco para este origen.';
        }
        $type = $types->count() === 1 ? $types->first() : null;
        $certificates = DB::table('certificado.certificado')->where('id_'.$origin, $id)->orderBy('id_certificado')->get();
        $hasCertificate = $type !== null && $certificates->contains('id_tipo_certificado', $type->id_tipo_certificado);

        return [...$data, 'origen' => $origin, 'id_origen' => $id,
            'id_tipo_certificado' => $type ? (int) $type->id_tipo_certificado : null,
            'tipo_certificado' => $type?->nombre_tipo, 'certificados_existentes' => $certificates,
            'estado_elegibilidad' => $blockers === [] ? 'Elegible' : 'Pendiente de validación',
            'puede_preparar' => $blockers === [] && ! $hasCertificate, 'puede_generar_pdf' => $blockers === [] && ! $hasCertificate,
            'puede_consultar_certificado_existente' => $hasCertificate, 'puede_emitir' => false,
            'motivos_bloqueo' => $blockers];
    }

    public function context(string $origin, int $id): object
    {
        if (in_array($origin, ['ponente_actividad', 'ponente_sesion'], true)) {
            return $this->activitySpeakerContext($id, $origin === 'ponente_sesion');
        }
        abort_unless(in_array($origin, ['reconocimiento', 'practica'], true), 422, 'Origen no admitido.');
        $evaluation = $origin === 'reconocimiento' ? $this->recognition($id) : $this->practice($id);
        abort_unless($evaluation['estado_elegibilidad'] === 'Elegible', 409, implode(' ', $evaluation['motivos_bloqueo']));
        $record = $evaluation[$origin];

        return (object) ['origen' => $origin, 'id_origen' => $id, 'id_persona' => (int) $record->id_persona,
            'id_area' => (int) $record->id_area, 'id_actividad' => null, 'id_sesion' => null,
            'id_tipo_certificado' => $evaluation['id_tipo_certificado'], 'tipo_certificado' => $evaluation['tipo_certificado'],
            'nombres' => $record->nombres, 'apellido_paterno' => $record->apellido_paterno, 'apellido_materno' => $record->apellido_materno,
            'datos_origen' => (array) $record,
            'supervisores' => $origin === 'practica' ? $evaluation['supervisores']->map(fn (object $row): array => array_diff_key(
                (array) $row, array_flip(['fecha_ingreso_trabajador', 'fecha_fin_trabajador'])
            ))->all() : [],
            'politica' => config('certification.origins.'.$origin)];
    }

    public function activitySpeakerContext(int $id, bool $bySession = false): object
    {
        return RequestReadMemo::remember(self::class.'.context.'.hash('sha256', serialize([$id, $bySession, config('certification.origins')])),
            fn (): object => $this->resolveActivitySpeakerContext($id, $bySession));
    }

    private function resolveActivitySpeakerContext(int $id, bool $bySession): object
    {
        if ($bySession) {
            app(SessionSpeakers::class)->assertSchema();
        }
        $row = RequestReadMemo::remember(self::class.'.speaker-row.'.$id, fn (): ?object => DB::table('certificado.actividad_ponente as ap')
            ->join('certificado.actividad as a', 'a.id_actividad', '=', 'ap.id_actividad')
            ->join('gestion.persona_ponente as pp', 'pp.id_persona_ponente', '=', 'ap.id_persona_ponente')
            ->join('gestion.persona as p', 'p.id_persona', '=', 'pp.id_persona')
            ->where('ap.id_actividad_ponente', $id)
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->first(['ap.*', 'a.id_area', 'a.nombre as actividad',
                'a.estado as estado_actividad', 'a.fecha_inicio', 'a.fecha_fin', 'pp.id_persona',
                'p.nombres', 'p.apellido_paterno', 'p.apellido_materno']));
        abort_unless($row, 404, 'El ponente no está asociado a una actividad existente.');
        $session = null;
        if ($bySession) {
            abort_unless($row->id_actividad_sesion !== null, 409, 'El vínculo histórico de actividad no acredita una sesión.');
            $session = RequestReadMemo::remember(ActivitySession::class.'.'.$row->id_actividad_sesion,
                fn (): ?ActivitySession => ActivitySession::query()
                    ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->find($row->id_actividad_sesion));
            $session = $session === null ? null : (object) $session->getRawOriginal();
            abort_unless($session && (int) $session->id_actividad === (int) $row->id_actividad, 409, 'La sesión no pertenece a la actividad.');
            abort_unless($session->estado === CertificationReadiness::FINAL_SESSION_STATE && $session->fecha !== null
                && $session->fecha <= today()->toDateString(), 409, 'La sesión debe estar Finalizada y tener fecha real no futura.');
            abort_unless($session->fecha >= $row->fecha_inicio && $session->fecha <= $row->fecha_fin,
                409, 'La fecha de sesión debe pertenecer al periodo de la actividad.');
        } else {
            abort_unless(($row->id_actividad_sesion ?? null) === null, 409, 'El vínculo de sesión no acredita la modalidad por actividad.');
        }
        abort_unless(in_array($row->estado_actividad, ['Activa', 'Cerrada'], true)
            && $row->fecha_inicio !== null && $row->fecha_fin !== null && $row->fecha_inicio <= $row->fecha_fin
            && ($bySession || $row->fecha_fin <= today()->toDateString()), 409, 'La actividad debe tener un periodo real y concluido.');
        $request = RequestReadMemo::remember(self::class.'.approved-request.'.$row->id_actividad,
            fn (): ?object => DB::table('certificado.solicitud_actividad')->where('id_actividad', $row->id_actividad)
                ->where('estado', 'Aprobada')->orderBy('id_solicitud_actividad')
                ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->first());
        abort_unless($request, 409, 'La actividad no tiene una solicitud aprobada.');
        $worker = $bySession ? null : DB::table('gestion.persona_trabajador')->where('id_persona', $row->id_persona)
            ->where('id_area', $row->id_area)->whereDate('fecha_ingreso', '<=', $session?->fecha ?? $row->fecha_fin)
            ->where(fn ($query) => $query->whereNull('fecha_fin')->orWhereDate('fecha_fin', '>=', $session?->fecha ?? $row->fecha_inicio))
            ->orderBy('id_persona_trabajador')->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->first();
        abort_unless($bySession || $worker, 409, 'El reconocimiento requiere una referencia laboral real del beneficiario en el ámbito de la actividad; no se creará un trabajador ficticio.');
        $types = RequestReadMemo::remember(self::class.'.recognition-type.'.config('certification.origins.reconocimiento.type_name'),
            fn (): Collection => DB::table('certificado.tipo_certificado')->where('nombre_tipo', config('certification.origins.reconocimiento.type_name'))
                ->where('estado', true)->get());
        abort_unless($types->count() === 1, 409, 'No existe un tipo Reconocimiento vigente e inequívoco.');

        return (object) ['origen' => $bySession ? 'ponente_sesion' : 'ponente_actividad', 'modalidad_reconocimiento' => $bySession ? 'sesion' : 'actividad',
            ...($bySession ? ['sesion' => $session->nombre, 'fecha_sesion' => $session->fecha] : []),
            'id_origen' => $id, 'id_actividad_ponente' => $id, 'id_actividad' => (int) $row->id_actividad,
            'actividad' => $row->actividad, 'id_sesion' => $bySession ? (int) $session->id_actividad_sesion : null, 'id_persona' => (int) $row->id_persona,
            'id_persona_ponente' => (int) $row->id_persona_ponente, 'id_area' => (int) $row->id_area,
            'id_tipo_certificado' => (int) $types->first()->id_tipo_certificado, 'tipo_certificado' => $types->first()->nombre_tipo,
            'nombres' => $row->nombres, 'apellido_paterno' => $row->apellido_paterno, 'apellido_materno' => $row->apellido_materno,
            'datos_origen' => ['titulo' => 'Reconocimiento como ponente', 'id_persona_trabajador' => $bySession ? null : (int) $worker->id_persona_trabajador,
                'fecha_inicio_actividad' => $row->fecha_inicio, 'fecha_fin_actividad' => $row->fecha_fin,
                'id_solicitud_actividad' => (int) $request->id_solicitud_actividad, 'estado_actividad' => $row->estado_actividad]];
    }

    /** @return Collection<int, object> */
    public function activityCertificates(object $context): Collection
    {
        if ($context->origen === 'ponente_sesion') {
            $rows = RequestReadMemo::remember(self::class.'.session-certificates.'.$context->id_sesion,
                fn (): Collection => DB::table('certificado.reconocimiento as r')
                    ->join('certificado.actividad_ponente as ap', 'ap.id_actividad_ponente', '=', 'r.id_actividad_ponente')
                    ->join('gestion.persona_ponente as pp', 'pp.id_persona_ponente', '=', 'ap.id_persona_ponente')
                    ->leftJoin('certificado.certificado as c', 'c.id_reconocimiento', '=', 'r.id_reconocimiento')
                    ->where('ap.id_actividad_sesion', $context->id_sesion)
                    ->get(['c.*', 'pp.id_persona as origin_person_id', 'r.id_reconocimiento as origin_recognition_id']))
                ->filter(fn (object $row): bool => (int) $row->origin_person_id === $context->id_persona);
            $recognitionCount = $rows->pluck('origin_recognition_id')->unique()->count();
            $certificates = $rows->filter(fn (object $row): bool => $row->id_certificado !== null)->values()
                ->map(function (object $row): object {
                    $row = clone $row;
                    unset($row->origin_recognition_id, $row->origin_person_id);

                    return $row;
                });
            abort_if($recognitionCount > $certificates->count(), 409, 'Existe un reconocimiento de sesión sin documento recuperable; requiere regularización.');
            abort_if($certificates->contains(fn (object $certificate): bool => (int) $certificate->id_persona !== $context->id_persona
                || (int) $certificate->id_tipo_certificado !== $context->id_tipo_certificado), 409, 'El reconocimiento tiene un certificado de otra identidad o tipo.');

            return $certificates;
        }
        $certificates = DB::table('certificado.certificado')->where('id_persona', $context->id_persona)
            ->where('id_tipo_certificado', $context->id_tipo_certificado)->whereNotNull('id_reconocimiento')->get();
        $matching = collect();
        $documentsByCertificate = $certificates->isEmpty() ? collect() : DB::table('certificado.certificado_documento')
            ->whereIn('id_certificado', $certificates->pluck('id_certificado'))->get()->groupBy('id_certificado');
        foreach ($certificates as $certificate) {
            $documents = $documentsByCertificate->get($certificate->id_certificado, collect());
            abort_if($documents->isEmpty(), 409, 'Existe un reconocimiento sin contexto documental comprobable; regularice su origen antes de preparar otro.');
            foreach ($documents as $document) {
                $approved = app(CertificateDocumentVersions::class)->approvedContent($document)['context'];
                if (($approved['origen'] ?? null) === $context->origen
                    && ($approved['id_sesion'] ?? null) === $context->id_sesion
                    && (int) ($approved['id_actividad'] ?? 0) === $context->id_actividad
                    && (int) ($approved['id_persona'] ?? 0) === $context->id_persona) {
                    $matching->put($certificate->id_certificado, $certificate);
                }
            }
        }

        return $matching->values();
    }

    /** @return array<string, mixed> */
    public function activityRecognition(int $id, bool $bySession = false): array
    {
        try {
            $context = $this->activitySpeakerContext($id, $bySession);
            $existing = $this->activityCertificates($context);

            return [...(array) $context, 'estado_elegibilidad' => 'Elegible', 'certificados_existentes' => $existing,
                'puede_consultar_certificado_existente' => $existing->isNotEmpty(),
                'puede_preparar' => $existing->isEmpty(), 'puede_generar_pdf' => $existing->isEmpty(),
                'puede_emitir' => false, 'motivos_bloqueo' => []];
        } catch (HttpException $exception) {
            $assignment = $bySession ? $this->assignment($id) : null;

            return ['origen' => $bySession ? 'ponente_sesion' : 'ponente_actividad', 'id_origen' => $id, 'modalidad_reconocimiento' => $bySession ? 'sesion' : 'actividad',
                'id_sesion' => $assignment?->id_actividad_sesion, 'estado_elegibilidad' => 'Pendiente de validación',
                'puede_preparar' => false, 'puede_generar_pdf' => false, 'puede_emitir' => false,
                'motivos_bloqueo' => [$exception->getMessage()]];
        }
    }

    /** @return array<string, mixed> */
    public function catalogs(): array
    {
        return [
            'tipos_actividad' => DB::table('certificado.tipo_actividad')->where('estado', true)
                ->orderBy('id_tipo_actividad')->get(['id_tipo_actividad', 'nombre', 'descripcion', 'estado']),
            'tipos_certificado' => DB::table('certificado.tipo_certificado')->where('estado', true)
                ->orderBy('id_tipo_certificado')->get(['id_tipo_certificado', 'nombre_tipo', 'estado']),
            'relacion_tipo_actividad_certificado' => null,
            'motivo_relacion' => 'Los catálogos son independientes; el esquema no define una equivalencia entre sus claves.',
        ];
    }

    /** @return array<string, mixed> */
    public function sessionSpeakers(?int $sessionId, int $activityId): array
    {
        if ($sessionId !== null) {
            $session = RequestReadMemo::remember(ActivitySession::class.'.'.$sessionId,
                fn (): ?ActivitySession => ActivitySession::find($sessionId));
            abort_unless($session, 404, 'Sesión no encontrada.');
            abort_unless((int) $session->id_actividad === $activityId, 409, 'La sesión no pertenece a la actividad.');
        }
        $hasSessionAssignments = Schema::hasColumn('certificado.actividad_ponente', 'id_actividad_sesion');
        $speakers = DB::table('certificado.actividad_ponente as ap')
            ->join('gestion.persona_ponente as ponente', 'ponente.id_persona_ponente', '=', 'ap.id_persona_ponente')
            ->join('gestion.persona as persona', 'persona.id_persona', '=', 'ponente.id_persona')
            ->leftJoin('gestion.institucion as institucion', 'institucion.id_institucion', '=', 'ponente.id_institucion')
            ->where('ap.id_actividad', $activityId)
            ->when($hasSessionAssignments,
                fn ($query) => $sessionId === null ? $query->whereNull('ap.id_actividad_sesion') : $query->where('ap.id_actividad_sesion', $sessionId))
            ->orderBy('ap.id_actividad_ponente')
            ->get(['ap.id_actividad_ponente', 'ap.id_actividad', 'ponente.id_persona_ponente', 'ponente.id_persona',
                'persona.nombres', 'persona.apellido_paterno', 'persona.apellido_materno',
                'ponente.id_institucion', 'institucion.nombre_institucion as institucion', 'ponente.cargo', 'ponente.especialidad']);

        if ($sessionId !== null && $hasSessionAssignments) {
            $contextRows = DB::table('certificado.actividad_ponente as ap')
                ->join('certificado.actividad as a', 'a.id_actividad', '=', 'ap.id_actividad')
                ->join('gestion.persona_ponente as pp', 'pp.id_persona_ponente', '=', 'ap.id_persona_ponente')
                ->join('gestion.persona as p', 'p.id_persona', '=', 'pp.id_persona')
                ->where('ap.id_actividad', $activityId)->where('ap.id_actividad_sesion', $sessionId)
                ->get(['ap.*', 'a.id_area', 'a.nombre as actividad', 'a.estado as estado_actividad',
                    'a.fecha_inicio', 'a.fecha_fin', 'pp.id_persona', 'p.nombres', 'p.apellido_paterno', 'p.apellido_materno']);
            foreach ($contextRows as $row) {
                RequestReadMemo::remember(self::class.'.speaker-row.'.$row->id_actividad_ponente, fn (): object => $row);
            }
            foreach ($speakers as $speaker) {
                $speaker->reconocimiento = $this->activityRecognition((int) $speaker->id_actividad_ponente, true);
            }

            return ['origen' => 'ponente_sesion', 'id_actividad' => $activityId, 'id_sesion' => $sessionId,
                'ponentes_actividad' => $this->sessionSpeakers(null, $activityId)['ponentes_actividad'],
                'ponentes_sesion' => $speakers, 'relacion_ponente_sesion_disponible' => true,
                'puede_preparar' => $speakers->contains(fn (object $speaker): bool => $speaker->reconocimiento['puede_preparar']),
                'puede_emitir' => false, 'motivos_bloqueo' => []];
        }

        return [
            'origen' => 'ponente', 'id_actividad' => $activityId, 'id_sesion' => $sessionId,
            'ponentes_actividad' => $speakers, 'ponentes_sesion' => null,
            'relacion_ponente_sesion_disponible' => false,
            'puede_preparar' => false, 'puede_generar_pdf' => false, 'puede_emitir' => false,
            'motivos_bloqueo' => [
                'certificado.actividad_ponente vincula actividad y persona_ponente; no identifica las sesiones de intervención.',
                'certificado.reconocimiento solo referencia persona_trabajador; no vincula al ponente ni a la actividad o sesión acreditada.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function practice(int $practiceId): array
    {
        $practice = DB::table('gestion.practica as practica')
            ->join('gestion.persona_practicante as practicante', 'practicante.id_persona_practicante', '=', 'practica.id_persona_practicante')
            ->join('gestion.persona as persona', 'persona.id_persona', '=', 'practicante.id_persona')
            ->join('gestion.institucion as institucion', 'institucion.id_institucion', '=', 'practica.id_institucion')
            ->join('gestion.area as area', 'area.id_area', '=', 'practica.id_area')
            ->where('practica.id_practica', $practiceId)
            ->first(['practica.*', 'practicante.id_persona', 'practicante.estado as estado_practicante',
                'persona.nombres', 'persona.apellido_paterno', 'persona.apellido_materno',
                'institucion.nombre_institucion as institucion', 'area.nombre as area']);
        abort_unless($practice, 409, 'La práctica no tiene un beneficiario, institución y área coherentes.');
        $periodOrdered = $practice->fecha_inicio !== null && $practice->fecha_fin !== null
            && $practice->fecha_inicio <= $practice->fecha_fin;
        $periodValid = $periodOrdered && $practice->fecha_fin <= today()->toDateString();
        $supervisors = DB::table('gestion.practica_supervisor as supervisor')
            ->join('gestion.persona_trabajador as trabajador', 'trabajador.id_persona_trabajador', '=', 'supervisor.id_persona_trabajador')
            ->join('gestion.persona as persona', 'persona.id_persona', '=', 'trabajador.id_persona')
            ->where('supervisor.id_practica', $practiceId)->orderBy('supervisor.id_practica_supervisor')
            ->get(['supervisor.*', 'trabajador.id_persona', 'trabajador.fecha_ingreso as fecha_ingreso_trabajador',
                'trabajador.fecha_fin as fecha_fin_trabajador', 'persona.nombres', 'persona.apellido_paterno', 'persona.apellido_materno']);

        $blockers = $this->policyBlockers('practica', $practice->estado);
        if (trim((string) $practice->nombres) === '' || trim((string) $practice->carrera) === ''
            || trim((string) $practice->institucion) === '' || trim((string) $practice->area) === '') {
            $blockers[] = 'La constancia requiere beneficiario, carrera, institución y área con datos completos.';
        }
        if (! $periodValid) {
            $blockers[] = 'El periodo debe tener inicio y fin ordenados y haber concluido.';
        }
        $supervisorStates = config('certification.origins.practica.supervisor_states', []);
        $supervisorStatesConfigured = is_array($supervisorStates) && $supervisorStates !== [];
        $coversPeriod = fn (object $row): bool => $periodOrdered
            && $row->fecha_inicio !== null
            && $row->fecha_inicio <= $practice->fecha_inicio && ($row->fecha_fin === null || $row->fecha_fin >= $practice->fecha_fin)
            && $row->fecha_ingreso_trabajador !== null && $row->fecha_ingreso_trabajador <= $practice->fecha_inicio
            && ($row->fecha_fin_trabajador === null || $row->fecha_fin_trabajador >= $practice->fecha_fin);
        $supervisionAccredited = $supervisorStatesConfigured && $supervisors->contains(fn (object $row): bool => $coversPeriod($row)
            && in_array($row->estado, $supervisorStates, true));
        if (! $supervisorStatesConfigured) {
            $blockers[] = 'Falta configurar los estados de supervisión autorizados por la política institucional.';
        }
        if (! $supervisionAccredited) {
            $blockers[] = 'No existe un responsable de supervisión que cubra el periodo según la política institucional.';
        }

        $evaluation = $this->evaluation('practica', $practiceId, [
            'origen' => 'practica', 'id_sesion' => null, 'practica' => $practice, 'supervisores' => $supervisors,
            'id_persona' => (int) $practice->id_persona, 'id_area' => (int) $practice->id_area, 'id_actividad' => null,
            'periodo_transcurrido_valido' => $periodValid, 'periodo_supervision_cubierto' => $supervisors->contains($coversPeriod),
            'supervision_acreditada' => $supervisionAccredited, 'requiere_asistencia_capacitacion' => false,
        ], $blockers);
        if ($evaluation['certificados_existentes']->isNotEmpty()) {
            $documents = DB::table('certificado.certificado_documento')
                ->whereIn('id_certificado', $evaluation['certificados_existentes']->where('id_persona', (int) $practice->id_persona)->pluck('id_certificado'))
                ->where('estado', 'Vigente')->orderBy('id_certificado_documento')
                ->get(['id_certificado', 'id_certificado_documento'])->groupBy('id_certificado');
            foreach ($evaluation['certificados_existentes'] as $certificate) {
                $current = $documents->get($certificate->id_certificado, collect());
                $certificate->id_certificado_documento = $current->count() === 1 ? (int) $current->first()->id_certificado_documento : null;
            }
        }

        return [...$evaluation, 'cumplimiento_requisitos_acreditado' => $evaluation['estado_elegibilidad'] === 'Elegible' ? true : null,
            'aprobacion_acreditada' => $evaluation['estado_elegibilidad'] === 'Elegible' ? true : null];
    }
}
