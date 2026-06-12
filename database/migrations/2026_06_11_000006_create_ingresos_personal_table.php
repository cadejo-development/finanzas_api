<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->create('ingresos_personal', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empleado_id');
            $table->unsignedBigInteger('registrado_por_id')->nullable(); // user_id de quien registra
            $table->string('empleado_nombre');                           // desnormalizado para historial
            $table->string('cargo_nombre')->nullable();
            $table->string('sucursal_nombre')->nullable();
            $table->date('fecha_ingreso');

            // Confirmación del primer día
            // pendiente | se_presento | no_show | reprogramado
            $table->string('confirmacion')->default('pendiente');
            $table->timestamp('confirmado_en')->nullable();
            $table->unsignedBigInteger('confirmado_por_id')->nullable();
            $table->date('nueva_fecha_ingreso')->nullable();   // si fue reprogramado
            $table->text('observaciones')->nullable();

            $table->string('aud_usuario')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('ingresos_personal');
    }
};
