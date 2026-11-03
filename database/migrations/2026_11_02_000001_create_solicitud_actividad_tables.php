<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Estados de una solicitud; los usan solicitud_actividad.estado y el
     * estado_anterior / estado_nuevo de cada decisión.
     */
    private const ESTADOS = [
        'Borrador',
        'Enviada',
        'En revisión',
        'Observada',
        'Pendiente de aprobación',
        'Aprobada',
        'Rechazada',
    ];

    private const TIPOS_DECISION = [
        'REVISION_CONFORME',
        'OBSERVAR',
        'DERIVAR_GERENCIA',
        'APROBAR',
        'RECHAZAR',
    ];

    /**
     * Run the migrations.
     *
     * Solicitudes de actividad y su historial de decisiones. Todas las FK son
     * RESTRICT: no se borran usuarios, áreas, actividades ni solicitudes que
     * tengan historial.
     */
    public function up(): void
    {
        Schema::create('certificado.solicitud_actividad', function (Blueprint $table) {
            $table->id('id_solicitud_actividad');
            $table->foreignId('id_area');
            $table->foreignId('id_usuario_solicitante');
            $table->foreignId('id_tipo_actividad');
            $table->string('nombre', 200);
            $table->text('descripcion')->nullable();
            $table->text('justificacion');
            $table->date('fecha_inicio_propuesta');
            $table->date('fecha_fin_propuesta');
            $table->string('sede_propuesta', 200)->nullable();
            $table->string('estado', 30)->default('Borrador');
            $table->timestampTz('fecha_solicitud', 6)->useCurrent();
            $table->foreignId('id_actividad')->nullable();

            $table->foreign('id_area', 'solicitud_actividad_id_area_foreign')
                ->references('id_area')->on('gestion.area')->restrictOnDelete();
            $table->foreign('id_usuario_solicitante', 'solicitud_actividad_id_usuario_solicitante_foreign')
                ->references('id_usuario')->on('acceso.usuario')->restrictOnDelete();
            $table->foreign('id_tipo_actividad', 'solicitud_actividad_id_tipo_actividad_foreign')
                ->references('id_tipo_actividad')->on('certificado.tipo_actividad')->restrictOnDelete();
            $table->foreign('id_actividad', 'solicitud_actividad_id_actividad_foreign')
                ->references('id_actividad')->on('certificado.actividad')->restrictOnDelete();

            // Una actividad nace de una sola solicitud; el UNIQUE ya indexa id_actividad.
            $table->unique('id_actividad', 'solicitud_actividad_id_actividad_unique');

            $table->index('id_area', 'solicitud_actividad_id_area_index');
            $table->index('id_usuario_solicitante', 'solicitud_actividad_id_usuario_solicitante_index');
            $table->index('id_tipo_actividad', 'solicitud_actividad_id_tipo_actividad_index');
            $table->index(['estado', 'fecha_solicitud'], 'solicitud_actividad_estado_fecha_solicitud_index');
        });

        DB::statement('ALTER TABLE certificado.solicitud_actividad ADD CONSTRAINT solicitud_actividad_fechas_check CHECK (fecha_fin_propuesta >= fecha_inicio_propuesta)');
        DB::statement('ALTER TABLE certificado.solicitud_actividad ADD CONSTRAINT solicitud_actividad_estado_check CHECK (estado IN ('.self::list(self::ESTADOS).'))');
        // Solo una solicitud aprobada puede quedar vinculada a una actividad.
        DB::statement("ALTER TABLE certificado.solicitud_actividad ADD CONSTRAINT solicitud_actividad_actividad_check CHECK (id_actividad IS NULL OR estado = 'Aprobada')");

        Schema::create('certificado.solicitud_decision', function (Blueprint $table) {
            $table->id('id_solicitud_decision');
            $table->foreignId('id_solicitud_actividad');
            $table->foreignId('id_usuario_responsable');
            $table->string('tipo_decision', 30);
            $table->string('estado_anterior', 30);
            $table->string('estado_nuevo', 30);
            $table->text('observacion')->nullable();
            $table->timestampTz('fecha_decision', 6)->useCurrent();

            $table->foreign('id_solicitud_actividad', 'solicitud_decision_id_solicitud_actividad_foreign')
                ->references('id_solicitud_actividad')->on('certificado.solicitud_actividad')->restrictOnDelete();
            $table->foreign('id_usuario_responsable', 'solicitud_decision_id_usuario_responsable_foreign')
                ->references('id_usuario')->on('acceso.usuario')->restrictOnDelete();

            // Historial ordenado. Al empezar por id_solicitud_actividad también sirve a la FK.
            $table->index(['id_solicitud_actividad', 'fecha_decision'], 'solicitud_decision_solicitud_fecha_index');
            $table->index('id_usuario_responsable', 'solicitud_decision_id_usuario_responsable_index');
        });

        DB::statement('ALTER TABLE certificado.solicitud_decision ADD CONSTRAINT solicitud_decision_tipo_check CHECK (tipo_decision IN ('.self::list(self::TIPOS_DECISION).'))');
        DB::statement('ALTER TABLE certificado.solicitud_decision ADD CONSTRAINT solicitud_decision_estado_anterior_check CHECK (estado_anterior IN ('.self::list(self::ESTADOS).'))');
        DB::statement('ALTER TABLE certificado.solicitud_decision ADD CONSTRAINT solicitud_decision_estado_nuevo_check CHECK (estado_nuevo IN ('.self::list(self::ESTADOS).'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('certificado.solicitud_decision');
        Schema::dropIfExists('certificado.solicitud_actividad');
    }

    /**
     * "'a', 'b', 'c'" para un IN (...). Los valores son constantes de esta clase.
     */
    private static function list(array $values): string
    {
        return implode(', ', array_map(fn (string $value) => "'$value'", $values));
    }
};
