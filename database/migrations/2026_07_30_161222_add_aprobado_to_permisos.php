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
            $table->string('aprobado_por', 255)->nullable()->after('observaciones_jefe');
            $table->timestampTz('aprobado_at')->nullable()->after('aprobado_por');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('permisos', function (Blueprint $table) {
            $table->dropColumn(['aprobado_por', 'aprobado_at']);
        });
    }
};
