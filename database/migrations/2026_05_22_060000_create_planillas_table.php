<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        Schema::connection('pgsql')->create('planillas', function (Blueprint $table) {
            $table->id();
            $table->integer('anio');
            $table->integer('mes');
            $table->integer('quincena'); // 1 o 2
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->unsignedBigInteger('sucursal_id')->nullable();
            $table->string('estado', 20)->default('borrador'); // borrador|aprobada|pagada
            $table->decimal('total_salarios', 12, 2)->nullable();
            $table->decimal('total_descuentos', 12, 2)->nullable();
            $table->decimal('total_patronal', 12, 2)->nullable();
            $table->decimal('total_neto', 12, 2)->nullable();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->timestamps();
        });

        // Índice único compuesto: permite múltiples planillas por período si difieren en sucursal_id
        // PostgreSQL permite NULL en unique index sin conflicto entre NULLs
        \Illuminate\Support\Facades\DB::connection('pgsql')->statement(
            'CREATE UNIQUE INDEX planillas_periodo_sucursal_unique ON planillas (anio, mes, quincena, sucursal_id) WHERE sucursal_id IS NOT NULL'
        );
        \Illuminate\Support\Facades\DB::connection('pgsql')->statement(
            'CREATE UNIQUE INDEX planillas_periodo_sin_sucursal_unique ON planillas (anio, mes, quincena) WHERE sucursal_id IS NULL'
        );
    }

    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('planillas');
    }
};
