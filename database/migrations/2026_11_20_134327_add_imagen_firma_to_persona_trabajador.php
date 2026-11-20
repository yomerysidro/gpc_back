<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('gestion.persona_trabajador', function (Blueprint $table): void {
            $table->text('imagen_firma_cifrada')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gestion.persona_trabajador', function (Blueprint $table): void {
            $table->dropColumn('imagen_firma_cifrada');
        });
    }
};
