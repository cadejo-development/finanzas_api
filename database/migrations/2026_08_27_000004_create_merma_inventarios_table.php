<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->create('merma_inventarios', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sucursal_id');
            $table->date('fecha');
            $table->unsignedBigInteger('usuario_id');
            $table->time('hora_inicio');
            $table->time('hora_cierre')->nullable();
            // borrador / enviado / aprobado
            $table->string('estado', 20)->default('borrador');
            $table->unsignedBigInteger('aprobado_por')->nullable();
            $table->timestampTz('aprobado_at')->nullable();
            $table->timestamps();

            $table->unique(['sucursal_id', 'fecha']);
            $table->index('fecha');
            $table->index('sucursal_id');
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('merma_inventarios');
    }
};
