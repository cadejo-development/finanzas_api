<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): string { return 'pgsql'; }

    public function up(): void
    {
        Schema::connection('pgsql')->table('plazas', function (Blueprint $table) {
            // Código único del catálogo (ej. AMKJAM001-17)
            // ya existe pero era nullable — lo dejamos nullable hasta importar
            // Solo agregamos las columnas que faltan:

            $table->string('puesto', 150)->nullable()->after('codigo');
            $table->string('sucursal_unidad', 150)->nullable()->after('puesto');
            $table->string('estado', 20)->nullable()->default('ocupada')->after('sucursal_unidad');  // ocupada | vacante
            $table->string('tipo_plaza', 20)->nullable()->default('permanente')->after('estado');    // permanente | interina | temporal
            $table->date('fecha_creacion')->nullable()->after('tipo_plaza');
            $table->date('fecha_ocupacion')->nullable()->after('fecha_creacion');
            $table->date('fecha_liberacion')->nullable()->after('fecha_ocupacion');
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('plazas', function (Blueprint $table) {
            $table->dropColumn([
                'puesto', 'sucursal_unidad', 'estado',
                'tipo_plaza', 'fecha_creacion', 'fecha_ocupacion', 'fecha_liberacion',
            ]);
        });
    }
};
