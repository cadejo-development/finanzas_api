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
            $table->string('archivo_nombre', 255)->nullable()->after('observaciones_jefe');
            $table->text('archivo_ruta')->nullable()->after('archivo_nombre');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('permisos', function (Blueprint $table) {
            $table->dropColumn(['archivo_nombre', 'archivo_ruta']);
        });
    }
};
