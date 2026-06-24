<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->table('contratos_empleado', function (Blueprint $table) {
            $table->text('funciones')->nullable()->after('notas');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('contratos_empleado', function (Blueprint $table) {
            $table->dropColumn('funciones');
        });
    }
};
