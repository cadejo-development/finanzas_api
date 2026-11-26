<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empleado_tipo_planilla', function (Blueprint $table) {
            $table->date('fecha_inicio')->nullable()->after('tipo_planilla_id');
            $table->date('fecha_fin')->nullable()->after('fecha_inicio');
        });

        // Backfill: fecha_inicio = created_at para registros existentes
        DB::statement("UPDATE empleado_tipo_planilla SET fecha_inicio = created_at::date WHERE fecha_inicio IS NULL");

        // Ahora hacerlo NOT NULL
        Schema::table('empleado_tipo_planilla', function (Blueprint $table) {
            $table->date('fecha_inicio')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('empleado_tipo_planilla', function (Blueprint $table) {
            $table->dropColumn(['fecha_inicio', 'fecha_fin']);
        });
    }
};
