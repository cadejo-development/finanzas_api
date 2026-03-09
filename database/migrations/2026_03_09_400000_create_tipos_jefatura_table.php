<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de tipos de jefatura.
 * Reemplaza el ENUM en empleado_jefaturas con una tabla normalizada.
 */
return new class extends Migration
{
    public function getConnection(): string { return 'pgsql'; }

    public function up(): void
    {
        Schema::connection('pgsql')->create('tipos_jefatura', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->unique();
            $table->string('nombre', 80);
            $table->string('descripcion', 200)->nullable();
            $table->boolean('activo')->default(true);
            $table->string('aud_usuario', 100)->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::connection('pgsql')->table('tipos_jefatura')->insert([
            ['codigo' => 'jefe_sucursal',       'nombre' => 'Jefe de Sucursal',            'descripcion' => 'Responsable de una sucursal u operación', 'activo' => true, 'aud_usuario' => 'seed', 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'jefe_unidad',          'nombre' => 'Jefe de Unidad',              'descripcion' => 'Responsable de una unidad o departamento', 'activo' => true, 'aud_usuario' => 'seed', 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'gerente_area',         'nombre' => 'Gerente de Área',             'descripcion' => 'Gerente con responsabilidad de área corporativa', 'activo' => true, 'aud_usuario' => 'seed', 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'gerente_sucursal',     'nombre' => 'Gerente de Sucursal',         'descripcion' => 'Gerente operativo de sucursal', 'activo' => true, 'aud_usuario' => 'seed', 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'gerente_operaciones',  'nombre' => 'Gerente de Operaciones',      'descripcion' => 'Gerente de operaciones multi-sucursal', 'activo' => true, 'aud_usuario' => 'seed', 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'gerente_financiero',   'nombre' => 'Gerente Financiero',          'descripcion' => 'Responsable del área financiera', 'activo' => true, 'aud_usuario' => 'seed', 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'gerente_general',      'nombre' => 'Gerente General',             'descripcion' => 'Máxima autoridad operativa de la empresa', 'activo' => true, 'aud_usuario' => 'seed', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('tipos_jefatura');
    }
};
