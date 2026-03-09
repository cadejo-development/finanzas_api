<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Define qué empleados tienen jefatura y de qué tipo.
 * Usa tipos_jefatura como catálogo normalizado.
 * sucursal_id es opcional: aplica cuando el tipo es jefe_sucursal / gerente_sucursal.
 */
return new class extends Migration
{
    public function getConnection(): string { return 'pgsql'; }

    public function up(): void
    {
        Schema::connection('pgsql')->create('empleado_jefaturas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empleado_id');
            $table->unsignedBigInteger('tipo_jefatura_id');
            $table->unsignedBigInteger('sucursal_id')->nullable();  // Aplica para jefe/gerente de sucursal
            $table->boolean('activo')->default(true);
            $table->string('aud_usuario', 100)->nullable();
            $table->timestamps();

            $table->foreign('empleado_id', 'ejef_empleado_fk')
                  ->references('id')->on('empleados')->onDelete('cascade');

            $table->foreign('tipo_jefatura_id', 'ejef_tipo_fk')
                  ->references('id')->on('tipos_jefatura')->onDelete('restrict');

            $table->foreign('sucursal_id', 'ejef_sucursal_fk')
                  ->references('id')->on('sucursales')->nullOnDelete();

            // Un empleado no puede tener el mismo tipo de jefatura en la misma sucursal dos veces
            $table->unique(['empleado_id', 'tipo_jefatura_id', 'sucursal_id'], 'ejef_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('empleado_jefaturas');
    }
};
