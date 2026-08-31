<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->create('inventario_justificaciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sucursal_id');
            $table->date('fecha_conteo');
            $table->unsignedInteger('producto_id');
            $table->string('justificacion', 100)->nullable();
            $table->string('justificacion_obs', 500)->nullable();
            $table->timestamps();

            $table->unique(['sucursal_id', 'fecha_conteo', 'producto_id'], 'inv_just_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('inventario_justificaciones');
    }
};
