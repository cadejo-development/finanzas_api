<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->table('expediente_datos_personales', function (Blueprint $table) {
            if (!Schema::connection('rrhh')->hasColumn('expediente_datos_personales', 'nacimiento_municipio_id')) {
                $table->unsignedSmallInteger('nacimiento_municipio_id')->nullable()->after('lugar_nacimiento');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('expediente_datos_personales', function (Blueprint $table) {
            if (Schema::connection('rrhh')->hasColumn('expediente_datos_personales', 'nacimiento_municipio_id')) {
                $table->dropColumn('nacimiento_municipio_id');
            }
        });
    }
};
