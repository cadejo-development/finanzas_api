<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->table('periodos_prueba', function (Blueprint $table) {
            $table->boolean('alerta_3_enviada')->default(false)->after('alerta_7_enviada');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('periodos_prueba', function (Blueprint $table) {
            $table->dropColumn('alerta_3_enviada');
        });
    }
};
