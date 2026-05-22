<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mueve todas las tablas de planillas de pgsql (core) a rrhh.
 * Se eliminan FKs cruzadas a empleados (cross-DB FKs no soportadas en PG).
 */
return new class extends Migration
{
    // Esta migración toca dos conexiones; el rollback usa la misma lógica inversa.
    protected $connection = 'rrhh';

    public function up(): void
    {
        // ── 1. Crear tablas en rrhh ────────────────────────────────────────────

        Schema::connection('rrhh')->create('planilla_config', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 100)->unique();
            $table->decimal('valor', 12, 4);
            $table->text('descripcion')->nullable();
            $table->timestamps();
        });

        Schema::connection('rrhh')->create('planilla_tabla_renta', function (Blueprint $table) {
            $table->id();
            $table->decimal('desde', 10, 2);
            $table->decimal('hasta', 10, 2)->nullable();
            $table->decimal('cuota_fija', 10, 2)->default(0);
            $table->decimal('porcentaje', 5, 2)->default(0);
            $table->decimal('sobre_exceso_de', 10, 2)->default(0);
            $table->date('vigente_desde');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::connection('rrhh')->create('planilla_acreedores', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150);
            $table->string('tipo', 50)->default('otro');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::connection('rrhh')->create('planillas', function (Blueprint $table) {
            $table->id();
            $table->integer('anio');
            $table->integer('mes');
            $table->integer('quincena');
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->unsignedBigInteger('sucursal_id')->nullable();
            $table->string('estado', 20)->default('borrador');
            $table->decimal('total_salarios', 12, 2)->nullable();
            $table->decimal('total_descuentos', 12, 2)->nullable();
            $table->decimal('total_patronal', 12, 2)->nullable();
            $table->decimal('total_neto', 12, 2)->nullable();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->timestamps();
        });

        DB::connection('rrhh')->statement(
            'CREATE UNIQUE INDEX planillas_periodo_sucursal_unique ON planillas (anio, mes, quincena, sucursal_id) WHERE sucursal_id IS NOT NULL'
        );
        DB::connection('rrhh')->statement(
            'CREATE UNIQUE INDEX planillas_periodo_sin_sucursal_unique ON planillas (anio, mes, quincena) WHERE sucursal_id IS NULL'
        );

        Schema::connection('rrhh')->create('planilla_ordenes_descuento', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empleado_id'); // FK lógica a pgsql.empleados
            $table->unsignedBigInteger('acreedor_id')->nullable();
            $table->string('concepto', 200);
            $table->decimal('monto_quincenal', 10, 2);
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->foreign('acreedor_id')->references('id')->on('planilla_acreedores');
        });

        Schema::connection('rrhh')->create('planilla_lineas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('planilla_id');
            $table->unsignedBigInteger('empleado_id'); // FK lógica a pgsql.empleados
            $table->decimal('salario_base', 10, 2)->default(0);
            $table->integer('dias_quincena')->default(15);
            $table->decimal('dias_laborados', 5, 2)->default(0);
            $table->decimal('salario_proporcional', 10, 2)->default(0);
            $table->decimal('afp_empleado', 10, 2)->default(0);
            $table->decimal('isss_empleado', 10, 2)->default(0);
            $table->decimal('renta', 10, 2)->default(0);
            $table->decimal('otros_descuentos', 10, 2)->default(0);
            $table->decimal('total_descuentos_empleado', 10, 2)->default(0);
            $table->decimal('salario_neto', 10, 2)->default(0);
            $table->decimal('afp_patronal', 10, 2)->default(0);
            $table->decimal('isss_patronal', 10, 2)->default(0);
            $table->decimal('insaforp_patronal', 10, 2)->default(0);
            $table->decimal('total_patronal', 10, 2)->default(0);
            $table->decimal('costo_total', 10, 2)->default(0);
            $table->jsonb('detalle_descuentos')->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();

            $table->foreign('planilla_id')->references('id')->on('planillas')->onDelete('cascade');
            $table->unique(['planilla_id', 'empleado_id']);
        });

        // ── 2. Eliminar tablas de pgsql (en orden inverso por FKs) ─────────────

        Schema::connection('pgsql')->dropIfExists('planilla_lineas');
        Schema::connection('pgsql')->dropIfExists('planilla_ordenes_descuento');
        Schema::connection('pgsql')->dropIfExists('planillas');
        Schema::connection('pgsql')->dropIfExists('planilla_acreedores');
        Schema::connection('pgsql')->dropIfExists('planilla_tabla_renta');
        Schema::connection('pgsql')->dropIfExists('planilla_config');
    }

    public function down(): void
    {
        // Recrear en pgsql
        Schema::connection('pgsql')->create('planilla_config', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 100)->unique();
            $table->decimal('valor', 12, 4);
            $table->text('descripcion')->nullable();
            $table->timestamps();
        });

        Schema::connection('pgsql')->create('planilla_tabla_renta', function (Blueprint $table) {
            $table->id();
            $table->decimal('desde', 10, 2);
            $table->decimal('hasta', 10, 2)->nullable();
            $table->decimal('cuota_fija', 10, 2)->default(0);
            $table->decimal('porcentaje', 5, 2)->default(0);
            $table->decimal('sobre_exceso_de', 10, 2)->default(0);
            $table->date('vigente_desde');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::connection('pgsql')->create('planilla_acreedores', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150);
            $table->string('tipo', 50)->default('otro');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::connection('pgsql')->create('planillas', function (Blueprint $table) {
            $table->id();
            $table->integer('anio');
            $table->integer('mes');
            $table->integer('quincena');
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->unsignedBigInteger('sucursal_id')->nullable();
            $table->string('estado', 20)->default('borrador');
            $table->decimal('total_salarios', 12, 2)->nullable();
            $table->decimal('total_descuentos', 12, 2)->nullable();
            $table->decimal('total_patronal', 12, 2)->nullable();
            $table->decimal('total_neto', 12, 2)->nullable();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->timestamps();
        });

        DB::connection('pgsql')->statement(
            'CREATE UNIQUE INDEX planillas_periodo_sucursal_unique ON planillas (anio, mes, quincena, sucursal_id) WHERE sucursal_id IS NOT NULL'
        );
        DB::connection('pgsql')->statement(
            'CREATE UNIQUE INDEX planillas_periodo_sin_sucursal_unique ON planillas (anio, mes, quincena) WHERE sucursal_id IS NULL'
        );

        Schema::connection('pgsql')->create('planilla_ordenes_descuento', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empleado_id');
            $table->unsignedBigInteger('acreedor_id')->nullable();
            $table->string('concepto', 200);
            $table->decimal('monto_quincenal', 10, 2);
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->foreign('empleado_id')->references('id')->on('empleados');
            $table->foreign('acreedor_id')->references('id')->on('planilla_acreedores');
        });

        Schema::connection('pgsql')->create('planilla_lineas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('planilla_id');
            $table->unsignedBigInteger('empleado_id');
            $table->decimal('salario_base', 10, 2)->default(0);
            $table->integer('dias_quincena')->default(15);
            $table->decimal('dias_laborados', 5, 2)->default(0);
            $table->decimal('salario_proporcional', 10, 2)->default(0);
            $table->decimal('afp_empleado', 10, 2)->default(0);
            $table->decimal('isss_empleado', 10, 2)->default(0);
            $table->decimal('renta', 10, 2)->default(0);
            $table->decimal('otros_descuentos', 10, 2)->default(0);
            $table->decimal('total_descuentos_empleado', 10, 2)->default(0);
            $table->decimal('salario_neto', 10, 2)->default(0);
            $table->decimal('afp_patronal', 10, 2)->default(0);
            $table->decimal('isss_patronal', 10, 2)->default(0);
            $table->decimal('insaforp_patronal', 10, 2)->default(0);
            $table->decimal('total_patronal', 10, 2)->default(0);
            $table->decimal('costo_total', 10, 2)->default(0);
            $table->jsonb('detalle_descuentos')->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
            $table->foreign('planilla_id')->references('id')->on('planillas')->onDelete('cascade');
            $table->foreign('empleado_id')->references('id')->on('empleados');
            $table->unique(['planilla_id', 'empleado_id']);
        });

        // Drop rrhh tables
        Schema::connection('rrhh')->dropIfExists('planilla_lineas');
        Schema::connection('rrhh')->dropIfExists('planilla_ordenes_descuento');
        Schema::connection('rrhh')->dropIfExists('planillas');
        Schema::connection('rrhh')->dropIfExists('planilla_acreedores');
        Schema::connection('rrhh')->dropIfExists('planilla_tabla_renta');
        Schema::connection('rrhh')->dropIfExists('planilla_config');
    }
};
