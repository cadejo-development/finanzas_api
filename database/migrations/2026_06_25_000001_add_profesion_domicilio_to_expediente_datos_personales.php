<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('rrhh')->table('expediente_datos_personales', function (Blueprint $table) {
            $table->string('profesion', 100)->nullable()->after('lugar_nacimiento');
            $table->string('domicilio', 200)->nullable()->after('profesion');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('expediente_datos_personales', function (Blueprint $table) {
            $table->dropColumn(['profesion', 'domicilio']);
        });
    }
};
