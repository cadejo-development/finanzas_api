<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('planilla_lineas');
    }
};
