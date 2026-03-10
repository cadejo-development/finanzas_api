<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        // 1 ── Crear catálogo tipos_sucursal
        Schema::connection('pgsql')->create('tipos_sucursal', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->unique();
            $table->string('nombre', 100);
            $table->timestamps();
        });

        // 2 ── Seed inicial
        DB::connection('pgsql')->table('tipos_sucursal')->insert([
            ['codigo' => 'operativa',        'nombre' => 'Operativa',         'created_at' => now(), 'updated_at' => now()],
            ['codigo' => 'area_corporativa', 'nombre' => 'Área Corporativa',  'created_at' => now(), 'updated_at' => now()],
        ]);

        // 3 ── Agregar FK en sucursales
        Schema::connection('pgsql')->table('sucursales', function (Blueprint $table) {
            $table->unsignedBigInteger('tipo_sucursal_id')->nullable()->after('tipo');
            $table->foreign('tipo_sucursal_id')->references('id')->on('tipos_sucursal');
        });

        // 4 ── Migrar datos existentes
        DB::connection('pgsql')->statement("
            UPDATE sucursales s
            SET tipo_sucursal_id = ts.id
            FROM tipos_sucursal ts
            WHERE s.tipo = ts.codigo
        ");

        // 5 ── Eliminar columna tipo (string)
        Schema::connection('pgsql')->table('sucursales', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
    }

    public function down(): void
    {
        // Re-agregar columna tipo
        Schema::connection('pgsql')->table('sucursales', function (Blueprint $table) {
            $table->string('tipo', 30)->nullable()->after('nombre');
        });

        // Restaurar datos
        DB::connection('pgsql')->statement("
            UPDATE sucursales s
            SET tipo = ts.codigo
            FROM tipos_sucursal ts
            WHERE s.tipo_sucursal_id = ts.id
        ");

        // Quitar FK y columna
        Schema::connection('pgsql')->table('sucursales', function (Blueprint $table) {
            $table->dropForeign(['tipo_sucursal_id']);
            $table->dropColumn('tipo_sucursal_id');
        });

        Schema::connection('pgsql')->dropIfExists('tipos_sucursal');
    }
};
