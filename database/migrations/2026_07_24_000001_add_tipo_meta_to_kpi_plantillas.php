<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        Schema::connection('pgsql')->table('kpi_plantillas', function (Blueprint $table) {
            $table->string('tipo_meta', 10)->default('fijo')->after('monto_objetivo');
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('kpi_plantillas', function (Blueprint $table) {
            $table->dropColumn('tipo_meta');
        });
    }
};
