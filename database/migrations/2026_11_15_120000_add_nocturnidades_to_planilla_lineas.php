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
            $table->decimal('horas_nocturnas',      7, 2)->default(0)->after('monto_horas_extras');
            $table->decimal('monto_nocturnidades', 10, 2)->default(0)->after('horas_nocturnas');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('planilla_lineas', function (Blueprint $table) {
            $table->dropColumn(['horas_nocturnas', 'monto_nocturnidades']);
        });
    }
};
