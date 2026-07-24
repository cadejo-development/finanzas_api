<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        Schema::connection('pgsql')->table('kpi_resultados', function (Blueprint $table) {
            $table->decimal('meta_periodo', 12, 2)->nullable()->after('valor_real');
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('kpi_resultados', function (Blueprint $table) {
            $table->dropColumn('meta_periodo');
        });
    }
};
