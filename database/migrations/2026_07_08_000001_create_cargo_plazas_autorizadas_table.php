<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql')->create('cargo_plazas_autorizadas', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('cargo_id');
            $table->unsignedInteger('departamento_id');
            $table->unsignedInteger('cantidad')->default(0);
            $table->timestamps();

            $table->foreign('cargo_id')
                  ->references('id')->on('cargos')
                  ->onDelete('cascade');
            $table->foreign('departamento_id')
                  ->references('id')->on('departamentos')
                  ->onDelete('cascade');
            $table->unique(['cargo_id', 'departamento_id']);
        });

        // Pre-poblar desde conteos actuales de plazas activas por cargo+departamento
        DB::connection('pgsql')->statement("
            INSERT INTO cargo_plazas_autorizadas (cargo_id, departamento_id, cantidad, created_at, updated_at)
            SELECT cargo_id, departamento_id, COUNT(*)::int, NOW(), NOW()
            FROM plazas
            WHERE activo = true
              AND cargo_id IS NOT NULL
              AND departamento_id IS NOT NULL
            GROUP BY cargo_id, departamento_id
            ON CONFLICT (cargo_id, departamento_id) DO NOTHING
        ");
    }

    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('cargo_plazas_autorizadas');
    }
};
