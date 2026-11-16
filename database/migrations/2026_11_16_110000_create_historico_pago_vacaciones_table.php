<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->create('historico_pago_vacaciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empleado_id');
            $table->unsignedBigInteger('planilla_id');
            $table->date('fecha_aniversario');
            $table->decimal('salario_base',   10, 2);
            $table->decimal('monto',          10, 2);  // 30% del salario mensual
            $table->timestamps();

            $table->unique(['empleado_id', 'fecha_aniversario'], 'uq_hpv_emp_aniversario');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('historico_pago_vacaciones');
    }
};
