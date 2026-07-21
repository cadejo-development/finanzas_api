<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->create('propina_config_sucursal', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sucursal_id')->unique();
            $table->string('nombre_grupo', 100)->nullable();
            $table->decimal('pct_propina_sobre_venta', 5, 4)->default(0.1000);
            $table->decimal('pct_distribucion_empleados', 5, 4)->default(0.6500);
            $table->integer('dias_quincena_1')->default(15);
            $table->integer('dias_quincena_2')->default(15);
            $table->boolean('activa')->default(true);
            $table->text('notas')->nullable();
            $table->timestamps();
        });

        // Tabla de flujos: sucursales que canalizan propina a través de otra
        Schema::connection('rrhh')->create('propina_flujos_sucursal', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sucursal_origen_id');
            $table->unsignedBigInteger('sucursal_destino_id');
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['sucursal_origen_id', 'sucursal_destino_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('propina_flujos_sucursal');
        Schema::connection('rrhh')->dropIfExists('propina_config_sucursal');
    }
};
