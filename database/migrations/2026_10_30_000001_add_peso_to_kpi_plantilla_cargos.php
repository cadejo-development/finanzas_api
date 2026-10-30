<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql')->table('kpi_plantilla_cargos', function (Blueprint $table) {
            $table->decimal('peso', 5, 2)->nullable()->after('cargo_id');
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('kpi_plantilla_cargos', function (Blueprint $table) {
            $table->dropColumn('peso');
        });
    }
};
