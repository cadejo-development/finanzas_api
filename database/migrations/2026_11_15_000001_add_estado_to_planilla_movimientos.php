<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->table('planilla_movimientos', function (Blueprint $table) {
            $table->string('estado', 20)->default('activo')->after('referencia_id');
            // activo = aplicado en la planilla
            // anulado = excluido manualmente (no afecta el cálculo)
            // finalizado = planilla cerrada/histórica
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('planilla_movimientos', function (Blueprint $table) {
            $table->dropColumn('estado');
        });
    }
};
