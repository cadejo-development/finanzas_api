<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->table('amonestaciones', function (Blueprint $table) {
            if (!Schema::connection('rrhh')->hasColumn('amonestaciones', 'archivo_nombre')) {
                $table->string('archivo_nombre', 255)->nullable()->after('accion_tomada');
            }
            if (!Schema::connection('rrhh')->hasColumn('amonestaciones', 'archivo_ruta')) {
                $table->string('archivo_ruta', 500)->nullable()->after('archivo_nombre');
            }
        });

        Schema::connection('rrhh')->table('desvinculaciones', function (Blueprint $table) {
            if (!Schema::connection('rrhh')->hasColumn('desvinculaciones', 'archivo_nombre')) {
                $table->string('archivo_nombre', 255)->nullable()->after('observaciones');
            }
            if (!Schema::connection('rrhh')->hasColumn('desvinculaciones', 'archivo_ruta')) {
                $table->string('archivo_ruta', 500)->nullable()->after('archivo_nombre');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('amonestaciones', function (Blueprint $table) {
            $table->dropColumn(['archivo_nombre', 'archivo_ruta']);
        });

        Schema::connection('rrhh')->table('desvinculaciones', function (Blueprint $table) {
            $table->dropColumn(['archivo_nombre', 'archivo_ruta']);
        });
    }
};
