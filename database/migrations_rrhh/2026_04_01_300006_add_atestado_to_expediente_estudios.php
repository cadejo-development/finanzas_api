<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('rrhh')->table('expediente_estudios', function (Blueprint $table) {
            $table->string('atestado_ruta', 500)->nullable()->after('notas');
            $table->string('atestado_mime', 120)->nullable()->after('atestado_ruta');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('expediente_estudios', function (Blueprint $table) {
            $table->dropColumn(['atestado_ruta', 'atestado_mime']);
        });
    }
};
