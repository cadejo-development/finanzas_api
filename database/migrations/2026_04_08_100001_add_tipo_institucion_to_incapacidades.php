<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('rrhh')->table('incapacidades', function (Blueprint $table) {
            $table->string('tipo_institucion', 20)->nullable()->after('tipo_incapacidad_id'); // isss | privada
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('incapacidades', function (Blueprint $table) {
            $table->dropColumn('tipo_institucion');
        });
    }
};
