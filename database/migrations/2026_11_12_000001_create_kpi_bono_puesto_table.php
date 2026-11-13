<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        Schema::connection('pgsql')->create('kpi_bono_puesto', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cargo_id');
            $table->unsignedBigInteger('sucursal_id')->nullable(); // null = aplica a todas las sucursales
            $table->decimal('monto_bono', 10, 2);
            $table->decimal('umbral_minimo', 5, 2)->default(80.00); // % mínimo de cumplimiento ponderado
            $table->string('aud_usuario', 100)->nullable();
            $table->timestamps();

            $table->unique(['cargo_id', 'sucursal_id'], 'kpi_bono_puesto_cargo_sucursal_unique');
            $table->foreign('cargo_id')->references('id')->on('cargos')->onDelete('cascade');
            $table->foreign('sucursal_id')->references('id')->on('sucursales')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('kpi_bono_puesto');
    }
};
