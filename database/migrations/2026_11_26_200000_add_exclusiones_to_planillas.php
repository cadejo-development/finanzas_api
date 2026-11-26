<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        // 1. Estado en planilla_lineas: 'activo' | 'excluido'
        Schema::connection('rrhh')->table('planilla_lineas', function (Blueprint $table) {
            $table->string('estado', 20)->default('activo')->after('planilla_id');
        });

        // 2. Tabla de exclusiones explícitas por planilla
        Schema::connection('rrhh')->create('planilla_exclusiones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('planilla_id');
            $table->unsignedBigInteger('empleado_id');
            $table->string('motivo', 500)->nullable();
            $table->unsignedBigInteger('excluido_por')->nullable();
            $table->timestamps();

            $table->unique(['planilla_id', 'empleado_id']);
            $table->foreign('planilla_id')->references('id')->on('planillas')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('planilla_exclusiones');
        Schema::connection('rrhh')->table('planilla_lineas', function (Blueprint $table) {
            $table->dropColumn('estado');
        });
    }
};
