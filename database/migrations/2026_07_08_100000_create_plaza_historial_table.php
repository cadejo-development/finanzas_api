<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        Schema::connection('pgsql')->create('plaza_historial', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('plaza_id');
            $table->unsignedBigInteger('empleado_id');

            // ingreso | traslado | ascenso | cambio_cargo | reingreso
            $table->string('motivo_entrada', 30)->default('ingreso');
            $table->date('fecha_inicio');

            // null = ocupante actual
            $table->date('fecha_fin')->nullable();
            // renuncia | despido | traslado | ascenso | cambio_cargo | fallecimiento | fin_contrato
            $table->string('motivo_salida', 30)->nullable();

            $table->text('notas')->nullable();
            $table->string('aud_usuario', 100)->nullable();
            $table->timestamps();

            $table->foreign('plaza_id')->references('id')->on('plazas')->cascadeOnDelete();
            $table->foreign('empleado_id')->references('id')->on('empleados')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('plaza_historial');
    }
};
