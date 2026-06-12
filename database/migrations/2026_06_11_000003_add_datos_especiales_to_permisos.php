<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->table('permisos', function (Blueprint $table) {
            // Parentesco (para permiso por fallecimiento de familiar)
            $table->string('relacion_familiar')->nullable()->after('motivo');
            // Fecha del evento asociado al permiso especial.
            // Paternidad: fecha de nacimiento. Matrimonio: fecha del evento.
            $table->date('fecha_evento')->nullable()->after('relacion_familiar');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('permisos', function (Blueprint $table) {
            $table->dropColumn(['relacion_familiar', 'fecha_evento']);
        });
    }
};
