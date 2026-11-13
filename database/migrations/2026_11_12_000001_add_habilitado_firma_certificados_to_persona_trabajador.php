<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Indica qué trabajadores pueden firmar certificados. Los existentes quedan
     * en false por el DEFAULT.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('gestion.persona_trabajador', 'habilitado_firma_certificados')) {
            DB::statement('ALTER TABLE gestion.persona_trabajador
                ADD COLUMN habilitado_firma_certificados BOOLEAN NOT NULL DEFAULT FALSE');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('gestion.persona_trabajador', 'habilitado_firma_certificados')) {
            DB::statement('ALTER TABLE gestion.persona_trabajador DROP COLUMN habilitado_firma_certificados');
        }
    }
};
