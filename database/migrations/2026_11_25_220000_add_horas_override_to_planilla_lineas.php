<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->table('planilla_lineas', function (Blueprint $table) {
            // null = usar valor de control de horarios; número = valor editado manualmente
            $table->decimal('horas_extras_override', 8, 2)->nullable()->after('horas_extras');
            $table->decimal('horas_nocturnas_override', 8, 2)->nullable()->after('horas_nocturnas');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('planilla_lineas', function (Blueprint $table) {
            $table->dropColumn(['horas_extras_override', 'horas_nocturnas_override']);
        });
    }
};
