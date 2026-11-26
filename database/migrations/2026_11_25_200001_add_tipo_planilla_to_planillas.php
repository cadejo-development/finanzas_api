<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->table('planillas', function (Blueprint $table) {
            $table->string('tipo_planilla', 50)->default('salarios')->after('sucursal_id');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('planillas', function (Blueprint $table) {
            $table->dropColumn('tipo_planilla');
        });
    }
};
