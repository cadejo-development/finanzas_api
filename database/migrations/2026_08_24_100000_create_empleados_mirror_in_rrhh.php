<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Crea una tabla "empleados" en rrhh_db como espejo exacto de core_db.empleados.
 * Propósito: poder declarar FK constraints dentro de rrhh_db sin cruzar conexiones.
 * El Observer EmpleadoObserver mantiene el espejo sincronizado en tiempo real.
 * Los datos existentes en rrhh_db NO se modifican.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('rrhh')->create('empleados', function (Blueprint $table) {
            // ID manual — mismo valor que core_db.empleados.id, sin auto-increment
            $table->unsignedBigInteger('id')->primary();

            $table->string('codigo', 20)->unique();
            $table->string('nombres', 120);
            $table->string('apellidos', 120);
            $table->string('email', 120)->nullable();
            $table->unsignedBigInteger('cargo_id')->nullable();
            $table->unsignedBigInteger('sucursal_id')->nullable();
            $table->boolean('activo')->default(true);
            $table->string('aud_usuario', 100)->nullable();
            $table->timestamps();

            // Columnas agregadas post-base (mismas que core_db)
            $table->unsignedBigInteger('user_id')->nullable();
            $table->date('fecha_ingreso')->nullable();
            $table->unsignedBigInteger('departamento_id')->nullable();
            $table->decimal('salario_base', 10, 2)->nullable();
            $table->boolean('sync_excluido')->default(false);
            $table->unsignedBigInteger('plaza_id')->nullable();

            $table->index('activo');
            $table->index('sucursal_id');
            $table->index('cargo_id');
        });

        // Sembrar: copia directa de core_db.empleados sin transformaciones
        DB::connection('pgsql')
            ->table('empleados')
            ->orderBy('id')
            ->chunk(200, function ($rows) {
                DB::connection('rrhh')->table('empleados')->insert(
                    $rows->map(fn ($r) => (array) $r)->toArray()
                );
            });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('empleados');
    }
};
