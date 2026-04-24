<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->table('incapacidades', function (Blueprint $table) {
            $table->string('nombre_institucion', 150)->nullable()->after('tipo_institucion');
            $table->string('medico_tratante', 150)->nullable()->after('nombre_institucion');
            $table->string('numero_certificado', 80)->nullable()->after('medico_tratante');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('incapacidades', function (Blueprint $table) {
            $table->dropColumn(['nombre_institucion', 'medico_tratante', 'numero_certificado']);
        });
    }
};
