<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('rrhh')->table('expediente_documentos', function (Blueprint $table) {
            $table->string('foto_frente_ruta', 500)->nullable()->after('notas');
            $table->string('foto_reverso_ruta', 500)->nullable()->after('foto_frente_ruta');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('expediente_documentos', function (Blueprint $table) {
            $table->dropColumn(['foto_frente_ruta', 'foto_reverso_ruta']);
        });
    }
};
