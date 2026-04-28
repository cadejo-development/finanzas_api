<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->table('cambios_salariales', function (Blueprint $table) {
            $table->string('documento_ruta')->nullable()->after('justificacion');
            $table->string('documento_nombre')->nullable()->after('documento_ruta');
            $table->string('documento_mime', 100)->nullable()->after('documento_nombre');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('cambios_salariales', function (Blueprint $table) {
            $table->dropColumn(['documento_ruta', 'documento_nombre', 'documento_mime']);
        });
    }
};
