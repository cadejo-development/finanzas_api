<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Agregar lugar de expedición a documentos
        Schema::connection('rrhh')->table('expediente_documentos', function (Blueprint $table) {
            $table->unsignedSmallInteger('lugar_exp_municipio_id')->nullable()->after('fecha_vencimiento');
            $table->string('lugar_exp_texto', 200)->nullable()->after('lugar_exp_municipio_id');
            // foto_frente_ruta y foto_reverso_ruta ya existen (fueron añadidas en la migración de fotos)
        });

        // Mejorar direcciones: agregar referencias a catálogos geo
        Schema::connection('rrhh')->table('expediente_direcciones', function (Blueprint $table) {
            $table->unsignedSmallInteger('departamento_id')->nullable()->after('tipo');
            $table->unsignedSmallInteger('distrito_id')->nullable()->after('departamento_id');
            $table->unsignedSmallInteger('municipio_id')->nullable()->after('distrito_id');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('expediente_documentos', function (Blueprint $table) {
            $table->dropColumn(['lugar_exp_municipio_id', 'lugar_exp_texto']);
        });

        Schema::connection('rrhh')->table('expediente_direcciones', function (Blueprint $table) {
            $table->dropColumn(['departamento_id', 'distrito_id', 'municipio_id']);
        });
    }
};
