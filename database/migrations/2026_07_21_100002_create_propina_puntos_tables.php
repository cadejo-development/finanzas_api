<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        // Puntos por defecto según cargo
        Schema::connection('rrhh')->create('propina_puntos_cargo', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cargo_id')->unique();
            $table->decimal('puntos_propina', 4, 2)->default(1.00);
            $table->text('notas')->nullable();
            $table->timestamps();
        });

        // Override de puntos por empleado (acuerdos especiales)
        Schema::connection('rrhh')->create('propina_puntos_empleado', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empleado_id');
            $table->decimal('puntos_propina', 4, 2)->default(1.00);
            $table->date('fecha_desde');
            $table->date('fecha_hasta')->nullable();
            $table->string('motivo', 300)->nullable();
            $table->unsignedBigInteger('registrado_por')->nullable();
            $table->timestamps();

            $table->index(['empleado_id', 'fecha_desde']);
        });

        // Propina adicional configurable por cargo y/o sucursal
        Schema::connection('rrhh')->create('propina_adicional_config', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sucursal_id')->nullable();
            $table->unsignedBigInteger('cargo_id')->nullable();
            $table->decimal('monto', 10, 2)->default(0);
            $table->string('descripcion', 200)->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->index(['sucursal_id', 'cargo_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('propina_adicional_config');
        Schema::connection('rrhh')->dropIfExists('propina_puntos_empleado');
        Schema::connection('rrhh')->dropIfExists('propina_puntos_cargo');
    }
};
