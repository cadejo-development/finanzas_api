<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection$connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->create('planilla_ordenes_descuento', function (Blueprint $table) {
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
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('planilla_ordenes_descuento');
    }
};
