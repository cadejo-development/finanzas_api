<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de empleados activos (se pobla desde SQL Server via script migrate_empleados.js).
 * Desacoplado de users: un empleado puede existir sin cuenta de sistema.
 * Cuando se crea un user, se puede vincular via user.empleado_id (FK opcional futura).
 */
return new class extends Migration
{
    public function getConnection(): string { return 'pgsql'; }

    public function up(): void
    {
        Schema::connection('pgsql')->create('empleados', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique();          // empCodigo del ERP
            $table->string('nombres', 120);
            $table->string('apellidos', 120);
            $table->string('email', 120)->nullable();
            $table->unsignedBigInteger('cargo_id')->nullable();
            $table->unsignedBigInteger('sucursal_id')->nullable();
            $table->boolean('activo')->default(true);
            $table->string('aud_usuario', 100)->nullable();
            $table->timestamps();

            $table->foreign('cargo_id', 'emp_cargo_fk')
                  ->references('id')->on('cargos')->nullOnDelete();

            $table->foreign('sucursal_id', 'emp_sucursal_fk')
                  ->references('id')->on('sucursales')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('empleados');
    }
};
