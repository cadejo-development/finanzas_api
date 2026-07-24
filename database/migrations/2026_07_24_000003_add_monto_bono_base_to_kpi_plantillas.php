<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql')->table('kpi_plantillas', function (Blueprint $table) {
            $table->decimal('monto_bono_base', 12, 2)->nullable()->after('monto_objetivo');
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('kpi_plantillas', function (Blueprint $table) {
            $table->dropColumn('monto_bono_base');
        });
    }
};
